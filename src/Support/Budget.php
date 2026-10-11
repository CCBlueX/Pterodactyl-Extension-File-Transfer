<?php

declare(strict_types=1);

namespace FileTransfer\Support;

use Closure;

/**
 * The time one job run may work. Queue workers stop jobs after 60 seconds, so a run
 * plans to end well before that and queues itself again.
 */
final class Budget
{
    /** Files up to this size are tried even before the speed is known. */
    private const int SMALL = 8 * 1024 * 1024;

    private const float MIN_REQUEST = 5.0;

    private readonly Closure $clock;

    private readonly float $start;

    private bool $fresh = true;

    /** @param (Closure(): float)|null $clock */
    public function __construct(private readonly float $seconds, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->start = ($this->clock)();
    }

    public function remaining(): float
    {
        return max(0.0, $this->seconds - (($this->clock)() - $this->start));
    }

    public function exhausted(): bool
    {
        return $this->remaining() < 1.0;
    }

    /** True until the run did its first piece of work. */
    public function fresh(): bool
    {
        return $this->fresh;
    }

    public function worked(): void
    {
        $this->fresh = false;
    }

    /**
     * Whether a whole-file request of the given size is expected to finish in this run.
     * The first file of a run always goes ahead, so even slow files get the full time once.
     */
    public function fits(int $bytes, float $rate): bool
    {
        if ($this->fresh) {
            return true;
        }

        $remaining = $this->remaining();

        if ($remaining < self::MIN_REQUEST) {
            return false;
        }

        if ($rate <= 0) {
            return $bytes <= self::SMALL;
        }

        return $bytes / $rate * 1.5 + 1 <= $remaining;
    }

    /** Seconds a single request may take, never less than a few seconds. */
    public function timeout(): int
    {
        return (int) max(self::MIN_REQUEST, floor($this->remaining()));
    }
}
