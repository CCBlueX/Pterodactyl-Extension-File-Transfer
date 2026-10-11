<?php

declare(strict_types=1);

namespace FileTransfer\Remote;

/**
 * How far a resumable download or upload got in one run.
 */
final readonly class Progress
{
    public function __construct(public int $bytes, public bool $done) {}
}
