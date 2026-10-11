<?php

declare(strict_types=1);

namespace FileTransfer\Support;

/**
 * Turns one folder listing into the files and folders to transfer.
 */
final class Tree
{
    /**
     * @param  string  $folder  The listed folder, relative to the source folder ("" for the source folder).
     * @param  list<Entry>  $entries
     * @param  list<string>  $only  Names to take from the source folder itself; empty takes everything.
     * @return array{files: list<array{path: string, size: int}>, folders: list<string>, skipped: list<array{path: string, reason: string}>}
     */
    public static function expand(string $folder, array $entries, array $only = []): array
    {
        $result = ['files' => [], 'folders' => [], 'skipped' => []];
        $filter = $folder === '' && $only !== [];
        $seen = [];

        foreach (Entry::sort($entries) as $entry) {
            if ($filter && ! in_array($entry->name, $only, true)) {
                continue;
            }

            $seen[] = $entry->name;
            $path = $folder === '' ? $entry->name : "{$folder}/{$entry->name}";

            if (! Paths::isName($entry->name)) {
                $result['skipped'][] = ['path' => $path, 'reason' => 'The name is not supported.'];
            } elseif ($entry->link) {
                $result['skipped'][] = ['path' => $path, 'reason' => 'Links and special files are skipped.'];
            } elseif ($entry->directory) {
                $result['folders'][] = $path;
            } else {
                $result['files'][] = ['path' => $path, 'size' => max(0, $entry->size)];
            }
        }

        foreach ($filter ? array_diff($only, $seen) : [] as $missing) {
            $result['skipped'][] = ['path' => $missing, 'reason' => 'It no longer exists.'];
        }

        return $result;
    }
}
