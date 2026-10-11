<?php

declare(strict_types=1);

namespace FileTransfer\Remote;

use RuntimeException;

/**
 * A problem with the remote server. When $connection is true the whole transfer is
 * affected (no connection or login), otherwise only the current file.
 */
final class RemoteException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $connection = false)
    {
        parent::__construct($message);
    }

    public static function connection(string $message): self
    {
        return new self($message, true);
    }
}
