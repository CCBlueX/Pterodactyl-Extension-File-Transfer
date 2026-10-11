<?php

declare(strict_types=1);

namespace FileTransfer\Remote;

use FileTransfer\Support\Budget;
use FileTransfer\Support\Entry;

/**
 * A logged in connection to an SFTP or FTP server. Paths are absolute ("/a/b").
 */
interface Client
{
    /** The folder the login starts in. */
    public function home(): string;

    /** SHA256 fingerprint of the server's host key, for SFTP. */
    public function fingerprint(): ?string;

    /** @return list<Entry> */
    public function list(string $folder): array;

    /** Size of a file, or null when it does not exist. */
    public function size(string $path): ?int;

    /** Appends the file from $offset to $local until it is complete or the run's time is up. */
    public function download(string $path, string $local, int $offset, int $maxBytes, Budget $budget): Progress;

    /** Sends $local from $offset on until it is complete or the run's time is up. Offset 0 replaces the file. */
    public function upload(string $local, string $path, int $offset, Budget $budget): Progress;

    /** Creates the folder and its parents. */
    public function makeDirectory(string $path): void;

    public function close(): void;
}
