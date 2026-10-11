<?php

declare(strict_types=1);

namespace FileTransfer\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file or folder of a transfer, relative to the transfer's source folder.
 *
 * @property int $id
 * @property int $transfer_id
 * @property string $path
 * @property bool $directory
 * @property int $size
 * @property string $status pending|ok|failed|skipped
 * @property string|null $message
 */
final class Item extends Model
{
    public $timestamps = false;

    protected $table = 'ext_file_transfer_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['directory' => 'boolean', 'size' => 'integer'];
    }

    /** @return array{path: string, status: string, message: string|null} */
    public function present(): array
    {
        return ['path' => $this->path, 'status' => $this->status, 'message' => $this->message];
    }
}
