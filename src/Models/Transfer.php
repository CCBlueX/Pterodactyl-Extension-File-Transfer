<?php

declare(strict_types=1);

namespace FileTransfer\Models;

use Carbon\CarbonImmutable;
use FileTransfer\Panel\Panel;
use FileTransfer\Support\Cursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * One import, export or copy between servers.
 *
 * @property int $id
 * @property string $type import|export|copy
 * @property string $status queued|running|cancelling|done|failed|cancelled
 * @property int|null $user_id
 * @property int|null $source_server_id
 * @property int|null $target_server_id
 * @property array{protocol: string, host: string, port: int, username: string}|null $remote
 * @property array<string, mixed>|null $credentials
 * @property string $source_root
 * @property list<string> $selection
 * @property string $target_root
 * @property array<string, mixed> $options
 * @property string|null $archive
 * @property array<string, mixed>|null $cursor
 * @property int $files_total
 * @property int $files_ok
 * @property int $files_failed
 * @property int $files_skipped
 * @property int $bytes_total
 * @property int $bytes_done
 * @property string|null $current_path
 * @property string|null $error
 * @property int $crashes
 * @property string|null $chain
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $heartbeat_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property Server|null $source
 * @property Server|null $target
 * @property User|null $user
 */
final class Transfer extends Model
{
    public const array TYPES = ['import', 'export', 'copy'];

    public const array ACTIVE = ['queued', 'running', 'cancelling'];

    protected $table = 'ext_file_transfer_transfers';

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'remote' => 'array',
            'credentials' => 'encrypted:array',
            'selection' => 'array',
            'options' => 'array',
            'cursor' => 'array',
            'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'source_server_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'target_server_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE);
    }

    /** @param Builder<self> $query */
    public function scopeInvolving(Builder $query, int ...$servers): void
    {
        $query->where(static fn (Builder $inner) => $inner->whereIn('source_server_id', $servers)->orWhereIn('target_server_id', $servers));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function position(): Cursor
    {
        return Cursor::fromArray($this->cursor);
    }

    public function remember(Cursor $cursor): void
    {
        $this->cursor = $cursor->toArray();
        $this->heartbeat_at = CarbonImmutable::now();
        $this->save();
    }

    /** "sftp://user@host:22" without any secret. */
    public function remoteLabel(): string
    {
        $remote = $this->remote ?? [];
        $host = str_contains((string) ($remote['host'] ?? ''), ':') ? "[{$remote['host']}]" : ($remote['host'] ?? '');

        return sprintf('%s://%s@%s:%d', $remote['protocol'] ?? 'sftp', $remote['username'] ?? '', $host, (int) ($remote['port'] ?? 0));
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        $end = $this->finished_at ?? CarbonImmutable::now();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'step' => $this->position()->step,
            'source' => $this->side($this->source, $this->type === 'import', $this->source_root),
            'target' => $this->side($this->target, $this->type === 'export', $this->target_root),
            'selection' => $this->selection,
            'options' => [
                'overwrite' => (bool) $this->option('overwrite', false),
                'wipe' => (bool) $this->option('wipe', false),
                'archive' => (bool) $this->option('archive', false),
            ],
            'archive' => $this->archive,
            'files' => [
                'total' => $this->files_total,
                'ok' => $this->files_ok,
                'failed' => $this->files_failed,
                'skipped' => $this->files_skipped,
            ],
            'bytes' => ['total' => $this->bytes_total, 'done' => $this->bytes_done],
            'current_path' => $this->current_path,
            'error' => $this->error,
            'user' => $this->user?->username,
            'elapsed' => $this->started_at ? max(0, $end->getTimestamp() - $this->started_at->getTimestamp()) : 0,
            'created_at' => $this->created_at->toAtomString(),
            'finished_at' => $this->finished_at?->toAtomString(),
        ];
    }

    /** @return array{kind: string, label: string, identifier: string|null, path: string} */
    private function side(?Server $server, bool $remote, string $path): array
    {
        if ($remote) {
            return ['kind' => 'remote', 'label' => $this->remoteLabel(), 'identifier' => null, 'path' => $path];
        }

        return ['kind' => 'server', 'label' => $server->name ?? 'Deleted server', 'identifier' => $server ? Panel::identifier($server) : null, 'path' => $path];
    }
}
