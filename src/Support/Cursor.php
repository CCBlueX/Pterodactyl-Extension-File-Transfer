<?php

declare(strict_types=1);

namespace FileTransfer\Support;

/**
 * Where a transfer stands between two job runs. Stored as JSON on the transfer.
 */
final class Cursor
{
    public const array STEPS = ['wipe', 'scan', 'transfer', 'cleanup'];

    /** A file that fails this often in a row, for example by timing out, is given up. */
    public const int MAX_ATTEMPTS = 3;

    /**
     * @param  list<string>  $folders  Folders still to list, relative to the source folder ("" is the folder itself).
     */
    public function __construct(
        public string $step = 'scan',
        public array $folders = [''],
        public int $item = 0,
        public int $offset = 0,
        public string $stage = 'fetch',
        public int $attempts = 0,
        public float $rate = 0.0,
    ) {}

    public static function start(bool $wipe): self
    {
        return new self($wipe ? 'wipe' : 'scan');
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $step = in_array($data['step'] ?? null, [...self::STEPS, 'done'], true) ? $data['step'] : 'scan';
        $folders = array_values(array_filter((array) ($data['folders'] ?? ['']), is_string(...)));

        return new self(
            $step,
            $folders,
            max(0, (int) ($data['item'] ?? 0)),
            max(0, (int) ($data['offset'] ?? 0)),
            ($data['stage'] ?? 'fetch') === 'push' ? 'push' : 'fetch',
            max(0, (int) ($data['attempts'] ?? 0)),
            max(0.0, (float) ($data['rate'] ?? 0)),
        );
    }

    /** @return array{step: string, folders: list<string>, item: int, offset: int, stage: string, attempts: int, rate: float} */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'folders' => $this->folders,
            'item' => $this->item,
            'offset' => $this->offset,
            'stage' => $this->stage,
            'attempts' => $this->attempts,
            'rate' => round($this->rate, 1),
        ];
    }

    public function advance(): void
    {
        $index = array_search($this->step, self::STEPS, true);
        $this->step = $index === false ? 'done' : (self::STEPS[$index + 1] ?? 'done');
    }

    /** The next folder to list. Folders are listed depth first, so items of one folder stay together. */
    public function nextFolder(): ?string
    {
        return array_pop($this->folders);
    }

    /** @param list<string> $folders */
    public function addFolders(array $folders): void
    {
        array_push($this->folders, ...array_reverse($folders));
    }

    /** Records a failed try of the current item. Returns false once it should be given up. */
    public function retry(): bool
    {
        $this->attempts++;

        return $this->attempts < self::MAX_ATTEMPTS;
    }

    /** The item is done, the next one starts from scratch. */
    public function finish(int $item): void
    {
        $this->item = max($this->item, $item);
        $this->offset = 0;
        $this->stage = 'fetch';
        $this->attempts = 0;
    }

    /** The file is fetched completely and has to be written to the target next. */
    public function fetched(): void
    {
        $this->stage = 'push';
        $this->offset = 0;
    }

    /** Remembers how fast whole files move, to plan which file still fits in a run. */
    public function measure(int $bytes, float $seconds): void
    {
        if ($bytes < 256 * 1024 || $seconds <= 0) {
            return;
        }

        $rate = $bytes / $seconds;
        $this->rate = $this->rate > 0 ? $this->rate * 0.6 + $rate * 0.4 : $rate;
    }
}
