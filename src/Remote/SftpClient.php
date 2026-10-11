<?php

declare(strict_types=1);

namespace FileTransfer\Remote;

use FileTransfer\Support\Budget;
use FileTransfer\Support\Entry;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

final class SftpClient implements Client
{
    /** Bytes per read or write call; small enough to check the time often. */
    private const int CHUNK = 4 * 1024 * 1024;

    private function __construct(private readonly SFTP $sftp, private readonly string $fingerprint) {}

    public static function connect(Endpoint $endpoint, string $address, int $timeout): self
    {
        try {
            $sftp = new SFTP(str_contains($address, ':') ? "[{$address}]" : $address, $endpoint->port, $timeout);
            $sftp->setTimeout($timeout);
            $key = $sftp->getServerPublicHostKey();
        } catch (Throwable) {
            $key = false;
        }

        if (! is_string($key)) {
            throw RemoteException::connection("Could not connect to {$endpoint->host}:{$endpoint->port}.");
        }

        $fingerprint = self::fingerprintOf($key);

        if ($endpoint->fingerprint !== null && ! hash_equals($endpoint->fingerprint, $fingerprint)) {
            throw RemoteException::connection("The host key of {$endpoint->host} changed. Test the connection again and check the new fingerprint.");
        }

        try {
            $credential = $endpoint->privateKey !== ''
                ? PublicKeyLoader::load($endpoint->privateKey, $endpoint->passphrase !== '' ? $endpoint->passphrase : false)
                : $endpoint->password;
        } catch (Throwable) {
            throw RemoteException::connection('The private key could not be read. Check the key and its passphrase.');
        }

        try {
            $loggedIn = $sftp->login($endpoint->username, $credential);
        } catch (Throwable) {
            $loggedIn = false;
        }

        if (! $loggedIn) {
            throw RemoteException::connection('The login was refused. Check the username and password or key.');
        }

        return new self($sftp, $fingerprint);
    }

    /** OpenSSH style "SHA256:..." fingerprint of a public host key line. */
    public static function fingerprintOf(string $key): string
    {
        $blob = base64_decode(explode(' ', $key)[1] ?? '', true) ?: '';

        return 'SHA256:'.mb_rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    public function home(): string
    {
        return $this->sftp->pwd() ?: '/';
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function list(string $folder): array
    {
        $list = $this->sftp->rawlist($folder);

        if (! is_array($list)) {
            throw new RemoteException("{$folder} could not be listed: ".$this->error());
        }

        $entries = [];

        foreach ($list as $name => $attributes) {
            $name = (string) $name;

            if ($name === '.' || $name === '..' || ! is_array($attributes)) {
                continue;
            }

            $type = (int) ($attributes['type'] ?? 0);
            $entries[] = new Entry($name, $type === 2, $type === 1 ? (int) ($attributes['size'] ?? 0) : 0, ! in_array($type, [1, 2], true));
        }

        return Entry::sort($entries);
    }

    public function size(string $path): ?int
    {
        $stat = $this->sftp->stat($path);

        return is_array($stat) && (int) ($stat['type'] ?? 0) === 1 ? (int) ($stat['size'] ?? 0) : null;
    }

    public function download(string $path, string $local, int $offset, int $maxBytes, Budget $budget): Progress
    {
        $file = $this->open($local, 'ab');
        ftruncate($file, $offset);
        fseek($file, $offset);

        try {
            do {
                $before = $offset;

                if ($this->sftp->get($path, $file, $offset, self::CHUNK) === false) {
                    throw new RemoteException("{$path} could not be read: ".$this->error());
                }

                fflush($file);
                $offset = (int) ftell($file);

                if ($offset > $maxBytes) {
                    throw new RemoteException('The file is larger than the size limit.');
                }

                if ($offset - $before < self::CHUNK) {
                    return new Progress($offset, true);
                }
            } while (! $budget->exhausted());

            return new Progress($offset, false);
        } finally {
            fclose($file);
        }
    }

    public function upload(string $local, string $path, int $offset, Budget $budget): Progress
    {
        $file = $this->open($local, 'rb');
        $size = (int) filesize($local);
        fseek($file, $offset);

        $sent = 0;
        $source = static function (int $length) use ($file, $budget, &$sent): ?string {
            // At least one chunk per run, so slow servers still make progress.
            if (($sent >= self::CHUNK && $budget->exhausted()) || feof($file)) {
                return null;
            }

            $data = fread($file, $length);

            if ($data === false || $data === '') {
                return null;
            }

            $sent += mb_strlen($data, '8bit');

            return $data;
        };

        try {
            // Offset 0 truncates an existing file; later runs continue writing at the offset.
            $ok = $this->sftp->put($path, $source, SFTP::SOURCE_CALLBACK, $offset > 0 ? $offset : -1);
        } finally {
            fclose($file);
        }

        if (! $ok) {
            throw new RemoteException("{$path} could not be written: ".$this->error());
        }

        return new Progress($offset + $sent, $offset + $sent >= $size);
    }

    public function makeDirectory(string $path): void
    {
        if ($path === '/' || $this->sftp->is_dir($path)) {
            return;
        }

        if (! $this->sftp->mkdir($path, -1, true) && ! $this->sftp->is_dir($path)) {
            throw new RemoteException("{$path} could not be created: ".$this->error());
        }
    }

    public function close(): void
    {
        $this->sftp->disconnect();
    }

    /** @return resource */
    private function open(string $local, string $mode)
    {
        $file = fopen($local, $mode);

        if ($file === false) {
            throw new RemoteException('The temporary file could not be opened.');
        }

        return $file;
    }

    private function error(): string
    {
        $error = mb_trim((string) $this->sftp->getLastSFTPError());

        return $error !== '' ? preg_replace('/^NET_SFTP_STATUS_/', '', $error) ?? $error : 'unknown error';
    }
}
