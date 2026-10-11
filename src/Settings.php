<?php

declare(strict_types=1);

namespace FileTransfer;

use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

final readonly class Settings
{
    public const array PROTOCOLS = ['sftp' => 'SFTP', 'ftp' => 'FTP', 'ftps' => 'FTPS (FTP over TLS)'];

    private const int MIB = 1024 * 1024;

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

    public function connectTimeout(): int
    {
        return min(60, max(3, (int) $this->get('connect_timeout', 10)));
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
