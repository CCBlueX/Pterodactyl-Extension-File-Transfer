<?php

declare(strict_types=1);

namespace FileTransfer\Transfers;

use FileTransfer\Jobs\RunTransfer;
use FileTransfer\Models\Transfer;
use FileTransfer\Panel\Panel;
use FileTransfer\Remote\Clients;
use FileTransfer\Remote\Endpoint;
use FileTransfer\Settings;
use FileTransfer\Support\Cursor;
use FileTransfer\Support\Entry;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Checks and queues new transfers. Archives are created here, before queueing, the same
 * way the file manager creates them: Wings answers once the archive is complete.
 */
final readonly class Starter
{
    public function __construct(private Panel $panel, private Clients $clients, private Settings $settings) {}

    /**
     * @param  list<string>  $selection
     * @param  array{overwrite: bool, wipe: bool, archive: bool}  $options
     */
    public function import(User $user, Server $server, Endpoint $endpoint, string $remoteRoot, array $selection, string $target, array $options): Transfer
    {
        $client = $this->clients->open($endpoint);

        try {
            // Fails before anything is wiped when the remote folder is not there.
            $client->list($remoteRoot);
            $fingerprint = $client->fingerprint();
        } finally {
            $client->close();
        }

        return $this->create([$server], function () use ($user, $server, $endpoint, $remoteRoot, $selection, $target, $options, $fingerprint): Transfer {
            $archive = null;

            if ($options['wipe'] && $options['archive'] && $this->panel->exists($server, $target)) {
                $names = array_map(static fn (Entry $entry): string => $entry->name, $this->panel->list($server, $target));

                if ($names !== []) {
                    $archive = $this->panel->compress($server, $target, $names)['name'];
                    $this->panel->log('server:file.compress', [$server], ['directory' => $target, 'files' => $names]);
                }
            }

            $transfer = $this->store([
                'type' => 'import',
                'user_id' => $user->id,
                'target_server_id' => $server->id,
                'remote' => $endpoint->remote(),
                'credentials' => $endpoint->secrets($fingerprint),
                'source_root' => $remoteRoot,
                'selection' => $selection,
                'target_root' => $target,
                'options' => ['overwrite' => $options['overwrite'], 'wipe' => $options['wipe'], 'archive' => $archive !== null],
                'archive' => $archive,
            ], $options['wipe']);

            $this->panel->log('server:file.pull', [$server], ['directory' => $target, 'url' => $transfer->remoteLabel().$this->describe($remoteRoot, $selection)]);

            return $transfer;
        });
    }

    /**
     * @param  list<string>  $selection
     * @param  array{overwrite: bool, archive: bool}  $options
     */
    public function export(User $user, Server $server, Endpoint $endpoint, string $root, array $selection, string $remoteTarget, array $options): Transfer
    {
        $client = $this->clients->open($endpoint);
        $fingerprint = $client->fingerprint();
        $client->close();

        return $this->create([$server], function () use ($user, $server, $endpoint, $root, $selection, $remoteTarget, $options, $fingerprint): Transfer {
            $archive = null;

            if ($options['archive']) {
                $names = $selection !== [] ? $selection : array_map(static fn (Entry $entry): string => $entry->name, $this->panel->list($server, $root));
                abort_if($names === [], 422, 'There is nothing to export in this folder.');

                ['name' => $archive, 'size' => $size] = $this->panel->compress($server, $root, $names);
                $this->panel->log('server:file.compress', [$server], ['directory' => $root, 'files' => $names]);

                if ($size > $this->settings->maxRemoteBytes()) {
                    $this->panel->delete($server, $root, [$archive]);

                    abort(422, sprintf('The archive is %s, larger than the %d MiB limit. Export the files without the archive option.', $this->size($size), intdiv($this->settings->maxRemoteBytes(), 1024 * 1024)));
                }
            }

            $transfer = $this->store([
                'type' => 'export',
                'user_id' => $user->id,
                'source_server_id' => $server->id,
                'remote' => $endpoint->remote(),
                'credentials' => $endpoint->secrets($fingerprint),
                'source_root' => $root,
                'selection' => $archive !== null ? [$archive] : $selection,
                'target_root' => $remoteTarget,
                'options' => ['overwrite' => $options['overwrite'], 'archive' => $archive !== null, 'delete_archive' => $archive !== null],
                'archive' => $archive,
            ], false);

            $this->panel->log('server:file.download', [$server], ['file' => $this->describe($root, $selection), 'destination' => $transfer->remoteLabel().$remoteTarget]);

            return $transfer;
        });
    }

    /** @param list<string> $selection */
    public function copy(User $user, Server $source, Server $target, string $root, array $selection, string $targetRoot, bool $overwrite): Transfer
    {
        return $this->create([$source, $target], function () use ($user, $source, $target, $root, $selection, $targetRoot, $overwrite): Transfer {
            $transfer = $this->store([
                'type' => 'copy',
                'user_id' => $user->id,
                'source_server_id' => $source->id,
                'target_server_id' => $target->id,
                'source_root' => $root,
                'selection' => $selection,
                'target_root' => $targetRoot,
                'options' => ['overwrite' => $overwrite],
            ], false);

            $this->panel->log('server:file.pull', [$target, $source], ['directory' => $targetRoot, 'url' => "{$source->name}:".$this->describe($root, $selection)]);

            return $transfer;
        });
    }

    /**
     * Runs $create while no other transfer may start on the same servers.
     *
     * @param  list<Server>  $servers
     * @param  callable(): Transfer  $create
     */
    private function create(array $servers, callable $create): Transfer
    {
        usort($servers, static fn (Server $a, Server $b): int => $a->id <=> $b->id);
        $locks = [];

        try {
            foreach ($servers as $server) {
                $lock = Cache::lock("ext-file-transfer:start:{$server->id}", 330);

                if (! $lock->get()) {
                    throw new HttpException(409, "A transfer is being started on {$server->name}. Try again in a moment.");
                }

                $locks[] = $lock;

                if (Transfer::query()->active()->involving($server->id)->exists()) {
                    throw new HttpException(409, "{$server->name} already has a transfer running. Wait for it to finish or cancel it.");
                }

                if (($problem = $this->panel->busy($server)) !== null) {
                    throw new HttpException(409, $problem);
                }
            }

            $transfer = $create();
            RunTransfer::start($transfer);

            return $transfer;
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    private function store(array $attributes, bool $wipe): Transfer
    {
        return Transfer::query()->create($attributes + ['status' => 'queued', 'cursor' => Cursor::start($wipe)->toArray()]);
    }

    /** @param list<string> $selection */
    private function describe(string $root, array $selection): string
    {
        $base = mb_rtrim($root, '/');

        return match (count($selection)) {
            0 => $root,
            1 => "{$base}/{$selection[0]}",
            default => sprintf('%s (%d items)', $root, count($selection)),
        };
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? sprintf('%.1f GiB', $bytes / 1024 ** 3) : sprintf('%.1f MiB', $bytes / 1024 ** 2);
    }
}
