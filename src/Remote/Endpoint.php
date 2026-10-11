<?php

declare(strict_types=1);

namespace FileTransfer\Remote;

/**
 * Where and how to log in. Secrets live here only for the duration of a request or a job.
 */
final readonly class Endpoint
{
    public function __construct(
        public string $protocol,
        public string $host,
        public int $port,
        public string $username,
        public string $password = '',
        public string $privateKey = '',
        public string $passphrase = '',
        public ?string $fingerprint = null,
        public bool $insecure = false,
    ) {}

    /** @param array<string, mixed> $data Validated request input. */
    public static function fromInput(array $data): self
    {
        $protocol = (string) $data['protocol'];

        return new self(
            $protocol,
            mb_strtolower(mb_trim(mb_trim((string) $data['host']), '[]')),
            (int) ($data['port'] ?? ($protocol === 'sftp' ? 22 : 21)),
            (string) $data['username'],
            (string) ($data['password'] ?? ''),
            $protocol === 'sftp' ? mb_trim((string) ($data['private_key'] ?? '')) : '',
            (string) ($data['passphrase'] ?? ''),
            isset($data['fingerprint']) && $data['fingerprint'] !== '' ? (string) $data['fingerprint'] : null,
            $protocol === 'ftps' && (bool) ($data['insecure'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<string, mixed>  $secrets
     */
    public static function fromStored(array $remote, array $secrets): self
    {
        return new self(
            (string) $remote['protocol'],
            (string) $remote['host'],
            (int) $remote['port'],
            (string) $remote['username'],
            (string) ($secrets['password'] ?? ''),
            (string) ($secrets['private_key'] ?? ''),
            (string) ($secrets['passphrase'] ?? ''),
            isset($secrets['fingerprint']) ? (string) $secrets['fingerprint'] : null,
            (bool) ($secrets['insecure'] ?? false),
        );
    }

    /**
     * Validation rules for the connection fields of a request.
     *
     * @param  list<string>  $protocols
     * @return array<string, mixed>
     */
    public static function rules(array $protocols): array
    {
        return [
            'protocol' => ['required', 'string', 'in:'.implode(',', $protocols)],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'private_key' => ['nullable', 'string', 'max:16384'],
            'passphrase' => ['nullable', 'string', 'max:1024'],
            'fingerprint' => ['nullable', 'string', 'max:128'],
            'insecure' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{protocol: string, host: string, port: int, username: string} */
    public function remote(): array
    {
        return ['protocol' => $this->protocol, 'host' => $this->host, 'port' => $this->port, 'username' => $this->username];
    }

    /** @return array{password: string, private_key: string, passphrase: string, fingerprint: string|null, insecure: bool} */
    public function secrets(?string $fingerprint = null): array
    {
        return [
            'password' => $this->password,
            'private_key' => $this->privateKey,
            'passphrase' => $this->passphrase,
            'fingerprint' => $fingerprint ?? $this->fingerprint,
            'insecure' => $this->insecure,
        ];
    }
}
