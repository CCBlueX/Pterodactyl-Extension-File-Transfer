<?php

declare(strict_types=1);

namespace FileTransfer\Panel;

use FileTransfer\Support\Entry;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Pterodactyl\Contracts\Files\CompressesFiles;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Daemon\DaemonConnection;
use RuntimeException;
use Throwable;

/**
 * The only place this extension touches Panel internals.
 *
 * File contents are streamed between Wings and a temporary file instead of going through
 * ReadsFileContents/WritesFileContents, which hold the whole file in PHP memory (the queue
 * worker has 128 MiB). Everything else uses the Files contracts.
 */
final readonly class Panel
{
    public function __construct(
        private ListsDirectories $lister,
        private CreatesDirectories $directories,
        private DeletesFiles $deleter,
        private CompressesFiles $compressor,
    ) {}

    /** The identifier the client API and the frontend use for a server. */
    public static function identifier(Server $server): string
    {
        return config('pterodactyl.features.new_server_identifiers') ? (string) $server->identifier : $server->uuidShort;
    }

    public function findServer(string $identifier): ?Server
    {
        return Server::query()
            ->when(
                str_starts_with($identifier, 'serv_'),
                static fn (Builder $query) => $query->whereIdentifier($identifier),
                static fn (Builder $query) => $query->where(mb_strlen($identifier) === 8 ? 'uuidShort' : 'uuid', $identifier),
            )
            ->first();
    }

    /** @param list<string> $permissions */
    public function can(User $user, Server $server, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $user->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }

    /** Why files of the server cannot be changed right now, or null. */
    public function busy(Server $server): ?string
    {
        try {
            $server->validateCurrentState();
        } catch (Throwable) {
            return "{$server->name} is suspended, installing, restoring a backup or being moved to another node.";
        }

        return null;
    }

    /**
     * Servers other than $except the user may pick for a copy.
     *
     * @return list<Server>
     */
    public function servers(User $user, Server $except, string $search): array
    {
        $query = $user->root_admin ? Server::query()->select('servers.*') : $user->accessibleServers();

        return $query
            ->where('servers.id', '!=', $except->id)
            ->when($search !== '', static fn (Builder $inner) => $inner->where(static fn (Builder $match) => $match
                ->where('servers.name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('servers.uuidShort', $search)))
            ->orderBy('servers.name')
            ->limit(50)
            ->with('node')
            ->get()
            ->all();
    }

    /** @return list<Entry> */
    public function list(Server $server, string $folder): array
    {
        $entries = [];

        foreach ($this->lister->list($server, $folder) as $file) {
            $isFile = (bool) ($file['file'] ?? true);
            $isLink = (bool) ($file['symlink'] ?? false);
            $directory = ! $isFile && ! $isLink;

            $entries[] = new Entry((string) ($file['name'] ?? ''), $directory, $isFile ? (int) ($file['size'] ?? 0) : 0, $isLink);
        }

        return Entry::sort($entries);
    }

    /** Wings answers 500 for missing paths, so existence is checked through the parent listing. */
    public function exists(Server $server, string $path): bool
    {
        if ($path === '/') {
            return true;
        }

        try {
            $names = array_map(static fn (Entry $entry): string => $entry->name, $this->list($server, dirname($path)));
        } catch (DaemonConnectionException) {
            return false;
        }

        return in_array(basename($path), $names, true);
    }

    /** Streams a server file into $local. Returns the bytes written. */
    public function download(Server $server, string $path, string $local, int $maxBytes, int $timeout): int
    {
        try {
            DaemonConnection::forNode($server->node)->request('GET', "/api/servers/{$server->uuid}/files/contents", [
                'query' => ['file' => $path],
                'sink' => $local,
                'timeout' => $timeout,
                'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new RuntimeException('The file is larger than the size limit.');
                    }
                },
            ]);
        } catch (Throwable $exception) {
            @unlink($local);

            throw new RuntimeException($this->problem($exception, 'read the file'));
        }

        clearstatcache(true, $local);

        return (int) filesize($local);
    }

    /** Streams $local into a server file, replacing it. Missing folders are created by Wings. */
    public function upload(Server $server, string $path, string $local, int $timeout): void
    {
        $file = fopen($local, 'rb');

        if ($file === false) {
            throw new RuntimeException('The temporary file could not be opened.');
        }

        try {
            DaemonConnection::forNode($server->node)->request('POST', "/api/servers/{$server->uuid}/files/write", [
                'query' => ['file' => $path],
                'body' => Utils::streamFor($file),
                'timeout' => $timeout,
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException($this->problem($exception, 'write the file'));
        } finally {
            is_resource($file) && fclose($file);
        }
    }

    public function makeDirectory(Server $server, string $path): void
    {
        if ($path !== '/') {
            $this->directories->create($server, basename($path), dirname($path));
        }
    }

    /** @param list<string> $names */
    public function delete(Server $server, string $folder, array $names): void
    {
        if ($names !== []) {
            $this->deleter->delete($server, $folder, $names);
        }
    }

    /**
     * Creates a .tar.gz of the names in $folder, inside $folder.
     *
     * @param  list<string>  $names
     * @return array{name: string, size: int}
     */
    public function compress(Server $server, string $folder, array $names): array
    {
        $archive = $this->compressor->compress($server, $folder, $names);

        return ['name' => (string) ($archive['name'] ?? ''), 'size' => (int) ($archive['size'] ?? 0)];
    }

    /**
     * Logs a core activity event, marked as coming from this extension.
     *
     * @param  list<Server>  $servers
     * @param  array<string, mixed>  $properties
     */
    public function log(string $event, array $servers, array $properties, ?User $actor = null): void
    {
        $activity = Activity::event($event)->subject(...$servers)->property($properties + ['via' => 'file-transfer']);

        if ($actor !== null) {
            $activity->actor($actor);
        }

        $activity->log();
    }

    /** A short explanation of a failed Wings request. */
    public function problem(Throwable $exception, string $action = 'reach the file'): string
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof DaemonConnectionException) {
                if (preg_match('/remote host: (.+?)\. \(request id/', $cause->getMessage(), $match) === 1) {
                    return "Wings could not {$action}: {$match[1]}.";
                }

                return $cause->getStatusCode() === 504
                    ? 'The node could not be reached or did not answer in time.'
                    : "Wings could not {$action} (error {$cause->getStatusCode()}).";
            }

            if (str_contains($cause->getMessage(), 'larger than the size limit')) {
                return 'The file is larger than the size limit.';
            }

            if (str_contains($cause->getMessage(), 'cURL error 28') || str_contains($cause->getMessage(), 'timed out')) {
                return 'The node could not be reached or did not answer in time.';
            }
        }

        return "Wings could not {$action}.";
    }
}
