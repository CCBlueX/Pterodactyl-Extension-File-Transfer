<?php

declare(strict_types=1);

namespace FileTransfer\Console;

use Carbon\CarbonImmutable;
use FileTransfer\Jobs\RunTransfer;
use FileTransfer\Models\Transfer;
use FileTransfer\Transfers\Runner;
use FileTransfer\Transfers\Session;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Restarts transfers whose job got lost (for example when the queue worker restarted),
 * and makes sure no login details or temporary files outlive a transfer.
 */
final class WatchCommand extends Command
{
    protected $signature = 'file-transfer:watch';

    protected $description = 'Restart stalled file transfers and clean up after finished ones.';

    public function handle(Runner $runner): int
    {
        $stalled = CarbonImmutable::now()->subMinutes(3);

        Transfer::query()->active()->where(static fn ($query) => $query->where('heartbeat_at', '<', $stalled)->orWhereNull('heartbeat_at'))->each(function (Transfer $transfer) use ($runner): void {
            if ($transfer->status === 'cancelling') {
                $runner->end($transfer, 'cancelled');
            } elseif ($transfer->crashes >= Runner::MAX_CRASHES * 2) {
                $runner->end($transfer, 'failed', 'The transfer stopped responding and was given up.');
            } else {
                $transfer->increment('crashes');
                RunTransfer::start($transfer);
                $this->line("Restarted transfer {$transfer->id}.");
            }
        });

        Transfer::query()->whereNotIn('status', Transfer::ACTIVE)->whereNotNull('credentials')->update(['credentials' => null]);

        $active = Transfer::query()->active()->pluck('id')->all();

        $root = dirname(Session::directory(0));

        foreach (is_dir($root) ? File::directories($root) : [] as $directory) {
            if (! in_array((int) basename($directory), $active, true)) {
                File::deleteDirectory($directory);
            }
        }

        return self::SUCCESS;
    }
}
