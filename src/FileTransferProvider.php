<?php

declare(strict_types=1);

namespace FileTransfer;

use FileTransfer\Console\WatchCommand;
use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class FileTransferProvider extends ExtensionProvider
{
    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();

        $this->registerPermissions('File Transfer.', [
            'import' => 'Import files from an SFTP or FTP server. Also needs the permissions to create and change files.',
            'export' => 'Send files to an SFTP or FTP server. Also needs the permission to read file contents.',
            'copy' => 'Copy files to or from other servers the user can manage files on.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('import_enabled', 'import_enabled', true, ['boolean'])
                ->label('Import from SFTP and FTP')
                ->field('toggle'),
            ExtensionSettingDefinition::make('export_enabled', 'export_enabled', true, ['boolean'])
                ->label('Export to SFTP and FTP')
                ->field('toggle'),
            ExtensionSettingDefinition::make('copy_enabled', 'copy_enabled', true, ['boolean'])
                ->label('Copy between servers')
                ->field('toggle'),
            ExtensionSettingDefinition::make('protocols', 'protocols', array_keys(Settings::PROTOCOLS), ['array'])
                ->label('Allowed protocols')
                ->multiselect(array_map(static fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(Settings::PROTOCOLS), Settings::PROTOCOLS)),
            ExtensionSettingDefinition::make('max_file_size', 'max_file_size', 100, ['required', 'integer', 'min:1', 'max:4096'])
                ->label('Largest file for imports and exports (MiB)')
                ->help('Each file is written to or read from the server in one request that has to finish within about 40 seconds.')
                ->field('number'),
            ExtensionSettingDefinition::make('max_copy_file_size', 'max_copy_file_size', 50, ['required', 'integer', 'min:1', 'max:4096'])
                ->label('Largest file for copies between servers (MiB)')
                ->help('Copies are streamed through the panel: read from one node and written to the other, each within about 40 seconds.')
                ->field('number'),
            ExtensionSettingDefinition::make('connect_timeout', 'connect_timeout', 10, ['required', 'integer', 'min:3', 'max:60'])
                ->label('Connection timeout (seconds)')
                ->field('number'),
            ExtensionSettingDefinition::make('allow_private_hosts', 'allow_private_hosts', false, ['boolean'])
                ->label('Allow private and local hosts')
                ->help('Lets users connect to private networks, localhost and link-local addresses. Leave this off unless every user is trusted.')
                ->field('toggle'),
        ]));

        $this->registerCommands([WatchCommand::class]);
        $this->registerSchedule(static function (Schedule $schedule): void {
            $schedule->command('file-transfer:watch')->everyMinute()->withoutOverlapping()->runInBackground();
        });
    }
}
