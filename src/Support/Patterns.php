<?php

declare(strict_types=1);

namespace FileTransfer\Support;

/**
 * Include and exclude filters as globs on paths relative to the source folder.
 *
 * A pattern without "/" matches a name at any depth ("*.log"), one with "/" matches the
 * whole relative path ("world/region/*"). "*" and "?" stay within one name, "**" crosses
 * folders. A trailing "/" limits a pattern to folders. Excluded folders are not entered;
 * includes pick files, folders are always searched.
 */
final readonly class Patterns
{
    public const int MAX = 20;

    public const int MAX_LENGTH = 255;

    /**
     * @param  list<string>  $include
     * @param  list<string>  $exclude
     */
    public function __construct(public array $include = [], public array $exclude = []) {}

    /**
     * Cleans user input: trims, drops blank lines and duplicates. Null when a pattern is not allowed.
     *
     * @param  list<mixed>  $patterns
     * @return list<string>|null
     */
    public static function clean(array $patterns): ?array
    {
        $clean = [];

        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                return null;
            }

            $pattern = mb_trim($pattern);

            if ($pattern === '') {
                continue;
            }

            if (mb_strlen($pattern) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F\\\\]/u', $pattern) !== 0 || ! mb_check_encoding($pattern, 'UTF-8')) {
                return null;
            }

            $clean[] = $pattern;
        }

        $clean = array_values(array_unique($clean));

        return count($clean) > self::MAX ? null : $clean;
    }

    /** @param array<string, mixed> $options Stored transfer options. */
    public static function fromOptions(array $options): self
    {
        return new self(
            array_values(array_map(strval(...), (array) ($options['include'] ?? []))),
            array_values(array_map(strval(...), (array) ($options['exclude'] ?? []))),
        );
    }

    public static function matches(string $pattern, string $path, bool $directory): bool
    {
        if (str_ends_with($pattern, '/')) {
            if (! $directory) {
                return false;
            }

            $pattern = mb_rtrim($pattern, '/');
        }

        $pattern = mb_ltrim($pattern, '/');

        if ($pattern === '') {
            return false;
        }

        $subject = str_contains($pattern, '/') ? $path : basename($path);

        return preg_match(self::regex($pattern), $subject) === 1;
    }

    public function empty(): bool
    {
        return $this->include === [] && $this->exclude === [];
    }

    /** Whether an entry at $path (relative to the source folder) is transferred, or for folders, entered. */
    public function allows(string $path, bool $directory): bool
    {
        foreach ($this->exclude as $pattern) {
            if (self::matches($pattern, $path, $directory)) {
                return false;
            }
        }

        if ($directory || $this->include === []) {
            return true;
        }

        foreach ($this->include as $pattern) {
            if (self::matches($pattern, $path, false)) {
                return true;
            }
        }

        return false;
    }

    /** Whether folders are created on their own. With includes only folders holding a match appear. */
    public function createsFolders(): bool
    {
        return $this->include === [];
    }

    private static function regex(string $pattern): string
    {
        $regex = '';
        $length = mb_strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($pattern, $i, 1);

            if ($char === '*' && mb_substr($pattern, $i + 1, 1) === '*') {
                $i++;

                // "a/**/b" also matches "a/b".
                if (mb_substr($pattern, $i + 1, 1) === '/') {
                    $i++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return '#^'.$regex.'$#u';
    }
}
