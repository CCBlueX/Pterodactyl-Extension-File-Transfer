<?php

declare(strict_types=1);

namespace FileTransfer\Tests\Unit;

use FileTransfer\Support\Entry;
use FileTransfer\Support\FtpListing;
use PHPUnit\Framework\TestCase;

final class FtpListingTest extends TestCase
{
    public function test_unix_listings_from_common_servers(): void
    {
        $output = implode("\r\n", [
            'total 24',
            'drwxr-xr-x    2 1000     1000         4096 Oct 11 12:00 .',
            'drwxr-xr-x    5 1000     1000         4096 Oct 11 12:00 ..',
            'drwxr-xr-x    3 ftp      ftp          4096 Oct 11 12:00 world',
            '-rw-r--r--    1 ftp      ftp      52428800 Jan  1  2025 server.jar',
            '-rw-r--r--    1 ftp      ftp          1234 Oct  3 09:15 file with  spaces.txt',
            'lrwxrwxrwx    1 ftp      ftp             7 Oct 11 12:00 latest.log -> old.log',
            '-rw-r--r--    1 owner             321 Oct 11 12:00 no-group.txt',
            '-rw-r--r--+   1 1000     1000          99 2025-10-11 12:00 iso.txt',
            'prw-r--r--    1 ftp      ftp             0 Oct 11 12:00 pipe',
            '',
        ]);

        $this->assertEquals([
            new Entry('world', true, 0),
            new Entry('server.jar', false, 52428800),
            new Entry('file with  spaces.txt', false, 1234),
            new Entry('latest.log', false, 7, true),
            new Entry('no-group.txt', false, 321),
            new Entry('iso.txt', false, 99),
            new Entry('pipe', false, 0, true),
        ], FtpListing::parse($output));
    }

    public function test_dos_listings_from_iis(): void
    {
        $output = "10-11-26  12:00PM       <DIR>          plugins\r\n10-11-26  09:05AM                 2048 server.properties\r\n";

        $this->assertEquals([new Entry('plugins', true, 0), new Entry('server.properties', false, 2048)], FtpListing::parse($output));
    }

    public function test_unknown_lines_are_ignored(): void
    {
        $this->assertSame([], FtpListing::parse("garbage\nmore garbage\n"));
    }

    public function test_entries_sort_folders_first(): void
    {
        $sorted = Entry::sort([new Entry('b.txt', false, 1), new Entry('Z', true, 0), new Entry('a.txt', false, 1), new Entry('a', true, 0)]);

        $this->assertSame(['a', 'Z', 'a.txt', 'b.txt'], array_map(static fn (Entry $entry): string => $entry->name, $sorted));
    }
}
