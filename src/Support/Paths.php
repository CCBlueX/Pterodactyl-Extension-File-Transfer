<?php

declare(strict_types=1);

namespace FileTransfer\Support;

/**
 * Absolute POSIX paths ("/a/b") for server and remote files. Anything that could leave
 * the starting folder is refused instead of being cleaned up.
 */
final class Paths
{
    public const int MAX_LENGTH = 4096;

    /** The path as "/a/b", or null when it contains "..", control characters or is too long. */
    public static function normalise(string $path): ?string
    {
        if (mb_strlen($path) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F\\\\]/u', $path) !== 0 || ! mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /** A single file name as listed by a server, never a path. */
    public static function isName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && mb_strlen($name) <= 255
            && ! str_contains($name, '/') && preg_match('/[\x00-\x1F\x7F\\\\]/u', $name) === 0 && mb_check_encoding($name, 'UTF-8');
    }

    /** Joins a normalised folder with a relative path; null when the result would leave the folder. */
    public static function join(string $folder, string $relative): ?string
    {
        $joined = self::normalise($folder.'/'.$relative);

        return $joined !== null && self::inside($joined, $folder) ? $joined : null;
    }

    public static function inside(string $path, string $folder): bool
    {
        $folder = mb_rtrim($folder, '/');

        return $folder === '' || $path === $folder || str_starts_with($path, $folder.'/');
    }

    /** "/a/b/c" relative to "/a" is "b/c". */
    public static function relative(string $path, string $folder): string
    {
        return mb_ltrim(mb_substr($path, mb_strlen(mb_rtrim($folder, '/'))), '/');
    }

    public static function parent(string $path): string
    {
        $parent = dirname($path);

        return $parent === '.' || $parent === '\\' ? '/' : $parent;
    }

    public static function name(string $path): string
    {
        return basename($path);
    }

    /**
     * Checks a selection of names inside a folder: every entry must be a plain name.
     *
     * @param  list<mixed>  $names
     * @return list<string>|null
     */
    public static function names(array $names): ?array
    {
        $clean = [];

        foreach ($names as $name) {
            if (! is_string($name) || ! self::isName($name)) {
                return null;
            }

            $clean[] = $name;
        }

        return array_values(array_unique($clean));
    }
}
