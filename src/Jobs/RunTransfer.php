<?php

declare(strict_types=1);

namespace FileTransfer\Jobs;

use FileTransfer\Models\Transfer;
use FileTransfer\Transfers\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one slice of a transfer (under 45 seconds), then queues itself again with the
 * cursor stored on the transfer until it is done.
 */
final class RunTransfer implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public int $transfer, public string $chain)
    {
        $this->onQueue('standard');
    }

    /** Starts a new chain of runs; any older chain of the transfer stops at its next run. */
    public static function start(Transfer $transfer, int $delay = 0): void
    {
        $transfer->forceFill(['chain' => Str::random(16), 'heartbeat_at' => now()])->save();

        self::dispatch($transfer->id, (string) $transfer->chain)->delay(now()->addSeconds($delay));
    }

    public function handle(Runner $runner): void
    {
        // A stalled run and its replacement must never work at the same time.
        $lock = Cache::lock("ext-file-transfer:run:{$this->transfer}", 90);

        if (! $lock->get()) {
            // An older chain is still busy: try again once it is done. A copy of this chain just stops.
            if (Cache::get("ext-file-transfer:chain:{$this->transfer}") !== $this->chain) {
                self::dispatch($this->transfer, $this->chain)->delay(now()->addSeconds(15));
            }

            return;
        }

        Cache::put("ext-file-transfer:chain:{$this->transfer}", $this->chain, 90);

        try {
            $transfer = Transfer::query()->with(['source.node', 'target.node', 'user'])->find($this->transfer);

            if ($transfer === null || ! $transfer->isActive() || $transfer->chain !== $this->chain) {
                return;
            }

            $next = $runner->run($transfer);
        } finally {
            $lock->release();
        }

        if ($next !== null) {
            self::dispatch($this->transfer, $this->chain)->delay(now()->addSeconds($next));
        }
    }

    /** The worker stopped the run, for example after a timeout: start over from the cursor. */
    public function failed(?Throwable $exception): void
    {
        $exception && report($exception);

        $transfer = Transfer::query()->find($this->transfer);

        if ($transfer === null || ! $transfer->isActive() || $transfer->chain !== $this->chain) {
            return;
        }

        $runner = app(Runner::class);
        $runner->crashed($transfer);

        $transfer->crashes >= Runner::MAX_CRASHES * 2
            ? $runner->end($transfer, 'failed', 'The transfer kept stopping and was given up.')
            : self::start($transfer, 30);
    }
}
