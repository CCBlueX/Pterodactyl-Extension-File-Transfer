<?php

declare(strict_types=1);

namespace FileTransfer\Transfers;

use FileTransfer\Models\Transfer;
use FileTransfer\Remote\Client;
use FileTransfer\Remote\Clients;
use FileTransfer\Remote\Endpoint;
use FileTransfer\Remote\RemoteException;
use Illuminate\Support\Facades\File;

/**
 * What one job run keeps around: the remote connection and a few folder listings.
 */
final class Session
{
    private ?Client $client = null;

    /** @var array<string, list<string>> */
    private array $listings = [];

    /** @var array<string, true> */
    private array $folders = [];

    public function __construct(private readonly Transfer $transfer, private readonly Clients $clients) {}

    public static function directory(int $transfer): string
    {
        return storage_path("app/private/file-transfer/{$transfer}");
    }

    public static function clean(int $transfer): void
    {
        File::deleteDirectory(self::directory($transfer));
    }

    public function client(): Client
    {
        if ($this->client === null) {
            if ($this->transfer->remote === null || $this->transfer->credentials === null) {
                throw RemoteException::connection('The login details are gone. Start the transfer again.');
            }

            $this->client = $this->clients->open(Endpoint::fromStored($this->transfer->remote, $this->transfer->credentials));
        }

        return $this->client;
    }

    /** The temporary file of the current item. */
    public function temporary(): string
    {
        $directory = self::directory($this->transfer->id);

        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0o700, true, true);
        }

        return "{$directory}/current.part";
    }

    /**
     * Names in a folder, listed once per run.
     *
     * @param  callable(string): list<string>  $list
     * @return list<string>
     */
    public function names(string $folder, callable $list): array
    {
        if (! array_key_exists($folder, $this->listings)) {
            if (count($this->listings) >= 16) {
                array_shift($this->listings);
            }

            $this->listings[$folder] = $list($folder);
        }

        return $this->listings[$folder];
    }

    public function added(string $folder, string $name): void
    {
        if (array_key_exists($folder, $this->listings)) {
            $this->listings[$folder][] = $name;
        }
    }

    /** Runs $create once per folder and run. */
    public function ensureFolder(string $folder, callable $create): void
    {
        if (! isset($this->folders[$folder])) {
            $create($folder);
            $this->folders[$folder] = true;
        }
    }

    public function close(): void
    {
        $this->client?->close();
        $this->client = null;
    }
}
