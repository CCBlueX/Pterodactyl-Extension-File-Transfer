<?php

declare(strict_types=1);

namespace FileTransfer\Support;

/**
 * Reads the output of an FTP LIST command. Servers answer in the Unix "ls -l" style
 * (vsftpd, ProFTPD, Pure-FTPd, FileZilla Server) or the DOS style (IIS).
 */
final class FtpListing
{
    private const string UNIX = '/^([\-dlbcps])[rwxsStTlL\-]{9}[+.@]?\s+\d+\s+(?:\S+\s+){1,2}?(\d+)\s+((?:[A-Za-z]{3}\s+\d{1,2}|\d{1,2}\s+[A-Za-z]{3})\s+(?:\d{1,2}:\d{2}|\d{4})|\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})\s(.+)$/';

    private const string DOS = '/^\d{1,2}-\d{1,2}-\d{2,4}\s+\d{1,2}:\d{2}\s*(?:[AaPp][Mm])?\s+(<DIR>|\d+)\s+(.+)$/';

    /** @return list<Entry> */
    public static function parse(string $output): array
    {
        $entries = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $entry = self::line(mb_rtrim($line, "\r"));

            if ($entry !== null && $entry->name !== '.' && $entry->name !== '..') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private static function line(string $line): ?Entry
    {
        if (preg_match(self::UNIX, $line, $match) === 1) {
            $name = $match[4];

            // Unix listings show links as "name -> target".
            if ($match[1] === 'l' && str_contains($name, ' -> ')) {
                $name = mb_substr($name, 0, (int) mb_strpos($name, ' -> '));
            }

            return new Entry($name, $match[1] === 'd', $match[1] === 'd' ? 0 : (int) $match[2], $match[1] === 'l' || ! in_array($match[1], ['-', 'd'], true));
        }

        if (preg_match(self::DOS, $line, $match) === 1) {
            $directory = $match[1] === '<DIR>';

            return new Entry($match[2], $directory, $directory ? 0 : (int) $match[1], false);
        }

        return null;
    }
}
