<?php

declare(strict_types=1);

namespace FileTransfer\Support;

/**
 * One file or folder in a listing. Links and special files are listed but never followed.
 */
final readonly class Entry
{
    public function __construct(
        public string $name,
        public bool $directory,
        public int $size,
        public bool $link = false,
    ) {}

    /**
     * Folders first, then by name.
     *
     * @param  list<self>  $entries
     * @return list<self>
     */
    public static function sort(array $entries): array
    {
        usort($entries, static fn (self $a, self $b): int => [$b->directory, mb_strtolower($a->name)] <=> [$a->directory, mb_strtolower($b->name)]);

        return $entries;
    }

    /** @return array{name: string, directory: bool, size: int, link: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'directory' => $this->directory, 'size' => $this->size, 'link' => $this->link];
    }
}
