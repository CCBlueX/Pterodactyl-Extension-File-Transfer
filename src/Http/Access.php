<?php

declare(strict_types=1);

namespace FileTransfer\Http;

use FileTransfer\Panel\Panel;
use FileTransfer\Settings;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * Who may start which transfer. Extension permissions come on top of the file permissions
 * a transfer needs, so a transfer can never do more than the user could in the file manager.
 */
final readonly class Access
{
    public const array READ = ['file.read', 'file.read-content'];

    public const array WRITE = ['file.create', 'file.update'];

    private const array NEEDS = [
        'import' => ['ext.file-transfer.import', ...self::WRITE],
        'export' => ['ext.file-transfer.export', ...self::READ],
        'copy' => ['ext.file-transfer.copy'],
    ];

    private const array LABELS = ['import' => 'Importing', 'export' => 'Exporting', 'copy' => 'Copying between servers'];

    public function __construct(private Settings $settings, private Panel $panel) {}

    public function allows(User $user, Server $server, string $type): bool
    {
        if (! $this->settings->enabled($type) || ! $this->panel->can($user, $server, self::NEEDS[$type])) {
            return false;
        }

        return $type !== 'copy' || $this->panel->can($user, $server, self::READ) || $this->panel->can($user, $server, self::WRITE);
    }

    public function authorize(User $user, Server $server, string $type): void
    {
        abort_unless($this->settings->enabled($type), 403, self::LABELS[$type].' is turned off by the administrator.');
        abort_unless($this->allows($user, $server, $type), 403, 'You do not have permission to do that.');
    }

    /** @return array{import: bool, export: bool, copy: bool} */
    public function features(User $user, Server $server): array
    {
        return [
            'import' => $this->allows($user, $server, 'import'),
            'export' => $this->allows($user, $server, 'export'),
            'copy' => $this->allows($user, $server, 'copy'),
        ];
    }

    /** Whether the user may see this server's transfers at all. */
    public function any(User $user, Server $server): bool
    {
        return in_array(true, $this->features($user, $server), true)
            || $user->can('ext.file-transfer.import', $server) || $user->can('ext.file-transfer.export', $server) || $user->can('ext.file-transfer.copy', $server);
    }
}
