<?php

declare(strict_types=1);

namespace FileTransfer;

use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

final readonly class Settings
{
    public const array PROTOCOLS = ['sftp' => 'SFTP', 'ftp' => 'FTP', 'ftps' => 'FTPS (FTP over TLS)'];

    public const int CONNECT_TIMEOUT = 30;

    public const int MAX_CONNECT_TIMEOUT = 120;

    private const int MIB = 1024 * 1024;

    /** Queue runs end after 55 seconds, so connections opened inside a run wait at most this long. */
    private const int RUN_CONNECT_TIMEOUT = 15;

    public function __construct(private ExtensionSettingsRegistry $registry) {}

    public function enabled(string $type): bool
    {
        return (bool) $this->get("{$type}_enabled", true);
    }

    /** @return list<string> */
    public function protocols(): array
    {
        $chosen = array_map(strval(...), (array) $this->get('protocols', array_keys(self::PROTOCOLS)));

        return array_values(array_intersect(array_keys(self::PROTOCOLS), $chosen));
    }

    /** Largest file in bytes for imports and exports. */
    public function maxRemoteBytes(): int
    {
        return max(1, (int) $this->get('max_file_size', 100)) * self::MIB;
    }

    /** Largest file in bytes for copies between servers. */
    public function maxCopyBytes(): int
    {
        return max(1, (int) $this->get('max_copy_file_size', 50)) * self::MIB;
    }

    /** Seconds to wait for a remote host when testing, browsing and starting a transfer. */
    public function connectTimeout(): int
    {
        return min(self::MAX_CONNECT_TIMEOUT, max(3, (int) $this->get('connect_timeout', self::CONNECT_TIMEOUT)));
    }

    /** Seconds to wait for a remote host while a transfer runs in the queue. */
    public function runConnectTimeout(): int
    {
        return min(self::RUN_CONNECT_TIMEOUT, $this->connectTimeout());
    }

    public function allowPrivateHosts(): bool
    {
        return (bool) $this->get('allow_private_hosts', false);
    }

    private function get(string $key, mixed $default): mixed
    {
        return $this->registry->get('file-transfer')?->get($key) ?? $default;
    }
}
