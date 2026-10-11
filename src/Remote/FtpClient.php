<?php

declare(strict_types=1);

namespace FileTransfer\Remote;

use CurlHandle;
use FileTransfer\Support\Budget;
use FileTransfer\Support\Entry;
use FileTransfer\Support\FtpListing;

/**
 * FTP and explicit FTPS through curl, which the panel image ships without ext-ftp.
 * One curl handle is reused so the control connection stays open between requests.
 */
final class FtpClient implements Client
{
    /** curl errors that mean the server cannot be used at all. */
    private const array CONNECTION_ERRORS = [5, 6, 7, 8, 35, 51, 53, 54, 58, 59, 60, 64, 66, 67, 77, 83, 91];

    private readonly CurlHandle $curl;

    private bool $listHidden = true;

    private string $home = '/';

    private function __construct(private readonly Endpoint $endpoint, private readonly string $address, private readonly int $timeout)
    {
        $this->curl = curl_init();
    }

    public static function connect(Endpoint $endpoint, string $address, int $timeout): self
    {
        $client = new self($endpoint, $address, $timeout);
        $client->request($client->url('/', true), [CURLOPT_NOBODY => true], connecting: true);

        $entry = curl_getinfo($client->curl, CURLINFO_FTP_ENTRY_PATH);
        $client->home = is_string($entry) && str_starts_with($entry, '/') ? $entry : '/';

        return $client;
    }

    public function home(): string
    {
        return $this->home;
    }

    public function fingerprint(): ?string
    {
        return null;
    }

    public function list(string $folder): array
    {
        $url = $this->url($folder, true);

        if ($this->listHidden) {
            try {
                return Entry::sort(FtpListing::parse($this->request($url, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'LIST -a'])));
            } catch (RemoteException $exception) {
                if ($exception->connection) {
                    throw $exception;
                }

                // Some servers do not understand "-a"; plain LIST hides dot files but works everywhere.
                $this->listHidden = false;
            }
        }

        return Entry::sort(FtpListing::parse($this->request($url, [CURLOPT_RETURNTRANSFER => true])));
    }

    public function size(string $path): ?int
    {
        try {
            $this->request($this->url($path), [CURLOPT_NOBODY => true]);
        } catch (RemoteException $exception) {
            if ($exception->connection) {
                throw $exception;
            }

            return null;
        }

        $size = curl_getinfo($this->curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);

        return is_int($size) && $size >= 0 ? $size : null;
    }

    public function download(string $path, string $local, int $offset, int $maxBytes, Budget $budget): Progress
    {
        $file = $this->open($local, 'ab');
        ftruncate($file, $offset);

        try {
            $this->request($this->url($path), [
                CURLOPT_FILE => $file,
                CURLOPT_RESUME_FROM => $offset,
                CURLOPT_MAXFILESIZE_LARGE => $maxBytes,
                CURLOPT_TIMEOUT => max(2, (int) floor($budget->remaining())),
            ], partial: true);
        } finally {
            fclose($file);
            clearstatcache(true, $local);
        }

        $bytes = (int) filesize($local);

        if ($bytes > $maxBytes) {
            throw new RemoteException('The file is larger than the size limit.');
        }

        return new Progress($bytes, curl_errno($this->curl) === 0);
    }

    public function upload(string $local, string $path, int $offset, Budget $budget): Progress
    {
        $size = (int) filesize($local);

        // The server's copy is the truth after an interrupted upload.
        if ($offset > 0) {
            $remote = $this->size($path);
            $offset = $remote !== null && $remote <= $size ? $remote : 0;
        }

        $file = $this->open($local, 'rb');
        fseek($file, $offset);

        try {
            $this->request($this->url($path), [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $file,
                CURLOPT_INFILESIZE => $size - $offset,
                CURLOPT_APPEND => $offset > 0,
                CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
                // Missing parents can only be created one folder at a time.
                CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_MULTICWD,
                CURLOPT_TIMEOUT => max(2, (int) floor($budget->remaining())),
            ], partial: true);
        } finally {
            fclose($file);
        }

        if (curl_errno($this->curl) === 0) {
            return new Progress($size, true);
        }

        return new Progress($offset + (int) curl_getinfo($this->curl, CURLINFO_SIZE_UPLOAD_T), false);
    }

    public function makeDirectory(string $path): void
    {
        if ($path !== '/') {
            $this->request($this->url($path, true), [CURLOPT_NOBODY => true, CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY, CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_MULTICWD]);
        }
    }

    public function close(): void
    {
        curl_close($this->curl);
    }

    /**
     * Absolute paths: "%2F" makes curl change into "/a/b" instead of a folder below the login folder.
     */
    private function url(string $path, bool $folder = false): string
    {
        $host = str_contains($this->endpoint->host, ':') ? "[{$this->endpoint->host}]" : $this->endpoint->host;
        $segments = implode('/', array_map(rawurlencode(...), array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''))));

        return "ftp://{$host}:{$this->endpoint->port}/%2F{$segments}".($folder || $segments === '' ? '/' : '');
    }

    /**
     * Runs one request. With $partial a timeout is fine, the transfer continues in the next run.
     *
     * @param  array<int, mixed>  $options
     */
    private function request(string $url, array $options, bool $connecting = false, bool $partial = false): string
    {
        curl_reset($this->curl);
        curl_setopt_array($this->curl, array_replace($this->baseOptions(), [CURLOPT_URL => $url, CURLOPT_TIMEOUT => $this->timeout * 3], $options));

        $result = curl_exec($this->curl);
        $error = curl_errno($this->curl);

        if ($error === 0 || ($partial && $error === 28)) {
            return is_string($result) ? $result : '';
        }

        $message = $this->message($error);

        if ($connecting || in_array($error, self::CONNECTION_ERRORS, true)) {
            throw RemoteException::connection($message);
        }

        throw new RemoteException($message);
    }

    /** @return array<int, mixed> */
    private function baseOptions(): array
    {
        $host = $this->endpoint->host;
        $options = [
            CURLOPT_USERNAME => $this->endpoint->username,
            CURLOPT_PASSWORD => $this->endpoint->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            // Passive data connections go to the address of the control connection, never to one the server names.
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_FTP_USE_EPSV => true,
            CURLOPT_FTP_USE_EPRT => false,
            CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_SINGLECWD,
            // Some curl versions never reuse FTPS connections; keep at most one open.
            CURLOPT_MAXCONNECTS => 1,
            CURLOPT_USE_SSL => $this->endpoint->protocol === 'ftps' ? CURLUSESSL_ALL : CURLUSESSL_NONE,
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_SSL_VERIFYPEER => ! $this->endpoint->insecure,
            CURLOPT_SSL_VERIFYHOST => $this->endpoint->insecure ? 0 : 2,
        ];

        $options[defined('CURLOPT_PROTOCOLS_STR') ? CURLOPT_PROTOCOLS_STR : CURLOPT_PROTOCOLS] = defined('CURLOPT_PROTOCOLS_STR') ? 'ftp' : CURLPROTO_FTP;

        if ($host !== $this->address) {
            // Connect to the address that was checked, not to whatever the name points to later.
            $address = str_contains($this->address, ':') ? "[{$this->address}]" : $this->address;
            $options[CURLOPT_RESOLVE] = ["{$host}:{$this->endpoint->port}:{$address}"];
        }

        return $options;
    }

    private function message(int $error): string
    {
        $host = "{$this->endpoint->host}:{$this->endpoint->port}";

        return match ($error) {
            6, 7 => "Could not connect to {$host}.",
            67 => 'The login was refused. Check the username and password.',
            28 => "{$host} did not answer in time.",
            64 => 'The server does not support FTP over TLS. Use plain FTP instead.',
            35, 51, 60 => 'The TLS certificate could not be verified. Allow self-signed certificates if you trust this server.',
            19, 78 => 'The file or folder does not exist.',
            63 => 'The file is larger than the size limit.',
            9 => 'Access was denied by the server.',
            default => mb_substr(curl_error($this->curl) ?: (curl_strerror($error) ?? 'The FTP request failed.'), 0, 250),
        };
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
}
