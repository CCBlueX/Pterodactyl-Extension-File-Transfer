<?php

declare(strict_types=1);

namespace FileTransfer\Transfers;

use Carbon\CarbonImmutable;
use FileTransfer\Models\Item;
use FileTransfer\Models\Transfer;
use FileTransfer\Panel\Panel;
use FileTransfer\Remote\Clients;
use FileTransfer\Remote\RemoteException;
use FileTransfer\Settings;
use FileTransfer\Support\Budget;
use FileTransfer\Support\Cursor;
use FileTransfer\Support\Entry;
use FileTransfer\Support\Paths;
use FileTransfer\Support\Tree;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;
use RuntimeException;
use Throwable;

/**
 * Does as much of a transfer as fits in one job run and remembers where it stopped.
 */
final readonly class Runner
{
    /** Seconds of work per run. Workers stop jobs after 60 seconds. */
    public const float SECONDS = 40.0;

    public const int MAX_FILES = 100_000;

    /** Runs in a row that may fail to connect before the transfer is given up. */
    public const int MAX_CRASHES = 3;

    private const int KEEP_PROBLEMS = 500;

    private const int KEEP_HISTORY = 10;

    public function __construct(private Panel $panel, private Clients $clients, private Settings $settings) {}

    /** Returns the seconds until the next run, or null when the transfer has ended. */
    public function run(Transfer $transfer, ?Budget $budget = null): ?int
    {
        $budget ??= new Budget(self::SECONDS);
        $cursor = $transfer->position();
        $session = new Session($transfer, $this->clients);

        try {
            if ($transfer->status === 'cancelling') {
                $this->end($transfer, 'cancelled');

                return null;
            }

            if ($transfer->status === 'queued') {
                Transfer::query()->whereKey($transfer->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => CarbonImmutable::now()]);
                $transfer->refresh();
            }

            $this->checkServers($transfer);

            while (($budget->fresh() || ! $budget->exhausted()) && $cursor->step !== 'cleanup' && $cursor->step !== 'done') {
                $finished = match ($cursor->step) {
                    'wipe' => $this->wipe($transfer),
                    'scan' => $this->scan($transfer, $cursor, $session, $budget),
                    default => $this->transfer($transfer, $cursor, $session, $budget),
                };

                if ($finished) {
                    $cursor->advance();
                }

                $budget->worked();

                $transfer->crashes = 0;
                $transfer->error = null;
                $transfer->remember($cursor);

                if (Transfer::query()->whereKey($transfer->id)->value('status') === 'cancelling') {
                    $this->end($transfer, 'cancelled');

                    return null;
                }

                if (! $finished) {
                    break;
                }
            }

            if ($cursor->step === 'cleanup' || $cursor->step === 'done') {
                $this->end($transfer, 'done');

                return null;
            }

            return 1;
        } catch (RemoteException $exception) {
            if (! $exception->connection || ++$transfer->crashes >= self::MAX_CRASHES) {
                $this->end($transfer, 'failed', $exception->getMessage());

                return null;
            }

            $transfer->error = $exception->getMessage().' Trying again shortly.';
            $transfer->save();

            return 20;
        } catch (Throwable $exception) {
            report($exception);
            $this->end($transfer, 'failed', mb_substr($exception->getMessage(), 0, 500));

            return null;
        } finally {
            $session->close();
        }
    }

    /** Ends a transfer: forgets the login, removes temporary files and trims the history. */
    public function end(Transfer $transfer, string $status, ?string $error = null): void
    {
        $transfer->fill([
            'status' => $status,
            'error' => $error,
            'credentials' => null,
            'current_path' => null,
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        Session::clean($transfer->id);

        if ($transfer->option('delete_archive') && $transfer->archive !== null && $transfer->source !== null) {
            try {
                $this->panel->delete($transfer->source, $transfer->source_root, [$transfer->archive]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        Item::query()->where('transfer_id', $transfer->id)->whereIn('status', ['ok', 'pending'])->delete();
        $keep = Item::query()->where('transfer_id', $transfer->id)->orderBy('id')->skip(self::KEEP_PROBLEMS)->value('id');
        $keep !== null && Item::query()->where('transfer_id', $transfer->id)->where('id', '>=', $keep)->delete();

        foreach (array_filter([$transfer->source_server_id, $transfer->target_server_id]) as $server) {
            $old = Transfer::query()->involving((int) $server)->whereNotIn('status', Transfer::ACTIVE)->latest('id')->skip(self::KEEP_HISTORY)->take(100)->pluck('id');
            Transfer::query()->whereKey($old)->delete();
        }
    }

    /** A run was stopped from outside, most likely while it moved one file for too long. */
    public function crashed(Transfer $transfer): void
    {
        $transfer->crashes++;
        $cursor = $transfer->position();

        if ($cursor->step === 'transfer' && ! $cursor->retry()) {
            $item = Item::query()->where('transfer_id', $transfer->id)->where('id', '>', $cursor->item)->where('status', 'pending')->orderBy('id')->first();
            $item && $this->settle($transfer, $cursor, $item, 'failed', 'Moving this file took too long; it is probably too large for one run.');
        }

        $transfer->remember($cursor);
    }

    private function checkServers(Transfer $transfer): void
    {
        foreach (['source_server_id' => $transfer->source, 'target_server_id' => $transfer->target] as $column => $server) {
            if ($transfer->{$column} !== null && $server === null) {
                throw new RuntimeException('A server of this transfer was deleted.');
            }
        }

        $target = $transfer->target;

        if ($target !== null && ($problem = $this->panel->busy($target)) !== null) {
            throw new RuntimeException($problem);
        }
    }

    private function wipe(Transfer $transfer): bool
    {
        $server = $this->server($transfer->target);
        $folder = $transfer->target_root;

        if (! $this->panel->exists($server, $folder)) {
            return true;
        }

        $keep = array_filter([$transfer->archive]);
        $names = array_values(array_diff(array_map(static fn (Entry $entry): string => $entry->name, $this->panel->list($server, $folder)), $keep));

        $this->panel->delete($server, $folder, $names);

        if ($names !== []) {
            $this->panel->log('server:file.delete', [$server], ['directory' => $folder, 'files' => $names], $transfer->user);
        }

        return true;
    }

    private function scan(Transfer $transfer, Cursor $cursor, Session $session, Budget $budget): bool
    {
        while (($budget->fresh() || ! $budget->exhausted()) && ($folder = $cursor->nextFolder()) !== null) {
            $path = Paths::join($transfer->source_root, $folder) ?? throw new RuntimeException('A folder path is not allowed.');
            $problem = null;

            try {
                $entries = $transfer->type === 'import' ? $session->client()->list($path) : $this->panel->list($this->server($transfer->source), $path);
            } catch (Throwable $exception) {
                if ($folder === '' || ($exception instanceof RemoteException && $exception->connection)) {
                    throw $exception instanceof RemoteException ? $exception : new RuntimeException("{$path} could not be listed: ".$this->panel->problem($exception, 'list the folder'));
                }

                $entries = null;
                $problem = $exception instanceof RemoteException ? $exception->getMessage() : $this->panel->problem($exception, 'list the folder');
            }

            $budget->worked();
            $tree = $entries === null
                ? ['files' => [], 'folders' => [], 'skipped' => [['path' => $folder, 'reason' => $problem ?? 'The folder could not be listed.']]]
                : Tree::expand($folder, $entries, $transfer->selection);

            DB::transaction(function () use ($transfer, $cursor, $tree): void {
                $rows = [
                    ...array_map(static fn (string $path): array => ['path' => $path, 'directory' => true, 'size' => 0, 'status' => 'pending', 'message' => null], $tree['folders']),
                    ...array_map(static fn (array $file): array => ['path' => $file['path'], 'directory' => false, 'size' => $file['size'], 'status' => 'pending', 'message' => null], $tree['files']),
                    ...array_map(static fn (array $skip): array => ['path' => $skip['path'], 'directory' => false, 'size' => 0, 'status' => 'skipped', 'message' => $skip['reason']], $tree['skipped']),
                ];

                foreach (array_chunk($rows, 500) as $chunk) {
                    Item::query()->insert(array_map(static fn (array $row): array => $row + ['transfer_id' => $transfer->id], $chunk));
                }

                $cursor->addFolders($tree['folders']);
                $transfer->files_total += count($tree['files']) + count($tree['skipped']);
                $transfer->files_skipped += count($tree['skipped']);
                $transfer->bytes_total += array_sum(array_column($tree['files'], 'size'));

                if ($transfer->files_total > self::MAX_FILES) {
                    throw new RuntimeException('There are more than '.number_format(self::MAX_FILES).' files. Pick fewer folders.');
                }

                $transfer->remember($cursor);
            });
        }

        return $cursor->folders === [];
    }

    private function transfer(Transfer $transfer, Cursor $cursor, Session $session, Budget $budget): bool
    {
        while ($budget->fresh() || ! $budget->exhausted()) {
            $item = Item::query()->where('transfer_id', $transfer->id)->where('id', '>', $cursor->item)->where('status', 'pending')->orderBy('id')->first();

            if ($item === null) {
                return true;
            }

            $transfer->current_path = $item->path;

            if (! $this->item($transfer, $cursor, $session, $budget, $item)) {
                return false;
            }

            $budget->worked();
            $transfer->remember($cursor);
        }

        return false;
    }

    /** Returns false when the item has to continue in the next run. */
    private function item(Transfer $transfer, Cursor $cursor, Session $session, Budget $budget, Item $item): bool
    {
        $source = Paths::join($transfer->source_root, $item->path);
        $target = Paths::join($transfer->target_root, $item->path);

        if ($source === null || $target === null) {
            return $this->settle($transfer, $cursor, $item, 'failed', 'The path is not allowed.');
        }

        try {
            if ($item->directory) {
                $this->folder($transfer, $session, $target);
                $budget->worked();

                return $this->settle($transfer, $cursor, $item, 'ok');
            }

            return $this->file($transfer, $cursor, $session, $budget, $item, $source, $target);
        } catch (RemoteException $exception) {
            if ($exception->connection) {
                throw $exception;
            }

            return $this->settle($transfer, $cursor, $item, 'failed', $exception->getMessage());
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 250);

            if (str_contains($message, 'did not answer in time') && $cursor->retry()) {
                return false;
            }

            return $this->settle($transfer, $cursor, $item, 'failed', $message);
        }
    }

    private function folder(Transfer $transfer, Session $session, string $target): void
    {
        if ($transfer->type === 'export') {
            $session->ensureFolder($target, fn (string $folder) => $session->client()->makeDirectory($folder));

            return;
        }

        $server = $this->server($transfer->target);

        try {
            $this->panel->makeDirectory($server, $target);
        } catch (Throwable $exception) {
            if (! $this->panel->exists($server, $target)) {
                throw new RuntimeException($this->panel->problem($exception, 'create the folder'));
            }
        }
    }

    private function file(Transfer $transfer, Cursor $cursor, Session $session, Budget $budget, Item $item, string $source, string $target): bool
    {
        $limit = $transfer->type === 'copy' ? $this->settings->maxCopyBytes() : $this->settings->maxRemoteBytes();

        if ($item->size > $limit) {
            $what = $transfer->type === 'copy' ? 'copies between servers' : ($transfer->type === 'import' ? 'imports' : 'exports');

            return $this->settle($transfer, $cursor, $item, 'skipped', sprintf('Larger than %d MiB, the limit for %s.', intdiv($limit, 1024 * 1024), $what));
        }

        if ($cursor->stage === 'fetch' && $cursor->offset === 0 && ! $transfer->option('overwrite', false) && $this->targetExists($transfer, $session, $target)) {
            return $this->settle($transfer, $cursor, $item, 'skipped', 'It already exists and overwriting is off.');
        }

        $local = $session->temporary();

        if ($cursor->stage === 'fetch') {
            if ($transfer->type === 'import') {
                $progress = $session->client()->download($source, $local, $cursor->offset, $limit, $budget);
                $cursor->offset = $progress->bytes;
                $budget->worked();

                if (! $progress->done) {
                    return false;
                }
            } else {
                if (! $budget->fits($item->size, $cursor->rate)) {
                    return false;
                }

                $started = microtime(true);
                $bytes = $this->panel->download($this->server($transfer->source), $source, $local, $limit, $budget->timeout());
                $cursor->measure($bytes, microtime(true) - $started);
                $budget->worked();
            }

            $cursor->fetched();
        }

        clearstatcache(true, $local);
        $size = is_file($local) ? (int) filesize($local) : 0;

        if ($transfer->type === 'export') {
            $session->ensureFolder(Paths::parent($target), fn (string $folder) => $session->client()->makeDirectory($folder));
            $progress = $session->client()->upload($local, $target, $cursor->offset, $budget);
            $cursor->offset = $progress->bytes;
            $budget->worked();

            if (! $progress->done) {
                return false;
            }
        } else {
            if (! $budget->fits($size, $cursor->rate)) {
                return false;
            }

            $started = microtime(true);
            $this->panel->upload($this->server($transfer->target), $target, $local, $budget->timeout());
            $cursor->measure($size, microtime(true) - $started);
            $budget->worked();
        }

        $session->added(Paths::parent($target), Paths::name($target));
        @unlink($local);
        $transfer->bytes_done += $size;

        return $this->settle($transfer, $cursor, $item, 'ok');
    }

    private function targetExists(Transfer $transfer, Session $session, string $target): bool
    {
        $folder = Paths::parent($target);

        $names = $session->names($folder, function (string $folder) use ($transfer, $session): array {
            try {
                $entries = $transfer->type === 'export' ? $session->client()->list($folder) : $this->panel->list($this->server($transfer->target), $folder);
            } catch (RemoteException $exception) {
                if ($exception->connection) {
                    throw $exception;
                }

                return [];
            } catch (Throwable) {
                // Wings answers 500 for missing folders: nothing can exist in them yet.
                return [];
            }

            return array_map(static fn (Entry $entry): string => $entry->name, $entries);
        });

        return in_array(Paths::name($target), $names, true);
    }

    /** Records how an item ended and moves the cursor past it. Always returns true. */
    private function settle(Transfer $transfer, Cursor $cursor, Item $item, string $status, ?string $message = null): bool
    {
        $item->update(['status' => $status, 'message' => $message !== null ? mb_substr($message, 0, 255) : null]);

        if (! $item->directory) {
            match ($status) {
                'ok' => $transfer->files_ok++,
                'skipped' => $transfer->files_skipped++,
                default => $transfer->files_failed++,
            };
        }

        $cursor->finish($item->id);
        @unlink(Session::directory($transfer->id).'/current.part');

        return true;
    }

    private function server(?Server $server): Server
    {
        return $server ?? throw new RuntimeException('A server of this transfer was deleted.');
    }
}
