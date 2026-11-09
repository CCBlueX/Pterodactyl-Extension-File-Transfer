<?php

declare(strict_types=1);

namespace FileTransfer\Tests\Unit;

use FileTransfer\Support\Entry;
use FileTransfer\Support\Patterns;
use FileTransfer\Support\Tree;
use PHPUnit\Framework\TestCase;

final class PatternsTest extends TestCase
{
    public function test_patterns_without_a_slash_match_names_at_any_depth(): void
    {
        $this->assertTrue(Patterns::matches('*.log', 'latest.log', false));
        $this->assertTrue(Patterns::matches('*.log', 'logs/2026/old.log', false));
        $this->assertFalse(Patterns::matches('*.log', 'latest.log.gz', false));
        $this->assertTrue(Patterns::matches('r.?.0.mca', 'world/region/r.1.0.mca', false));
        $this->assertFalse(Patterns::matches('r.?.0.mca', 'world/region/r.10.0.mca', false));
    }

    public function test_patterns_with_a_slash_match_the_whole_path(): void
    {
        $this->assertTrue(Patterns::matches('world/region/*', 'world/region/r.0.0.mca', false));
        $this->assertTrue(Patterns::matches('/world/region/*', 'world/region/r.0.0.mca', false));
        $this->assertFalse(Patterns::matches('world/*', 'world/region/r.0.0.mca', false));
        $this->assertFalse(Patterns::matches('region/*', 'world/region/r.0.0.mca', false));
        $this->assertTrue(Patterns::matches('world/**', 'world/region/r.0.0.mca', false));
        $this->assertTrue(Patterns::matches('plugins/**/config.yml', 'plugins/config.yml', false));
        $this->assertTrue(Patterns::matches('plugins/**/config.yml', 'plugins/Essentials/sub/config.yml', false));
    }

    public function test_a_trailing_slash_only_matches_folders(): void
    {
        $this->assertTrue(Patterns::matches('cache/', 'plugins/cache', true));
        $this->assertFalse(Patterns::matches('cache/', 'plugins/cache', false));
        $this->assertFalse(Patterns::matches('/', 'anything', true));
    }

    public function test_regex_characters_are_literal(): void
    {
        $this->assertTrue(Patterns::matches('a+b(1).txt', 'a+b(1).txt', false));
        $this->assertFalse(Patterns::matches('a+b(1).txt', 'aab1.txt', false));
        $this->assertTrue(Patterns::matches('#*', '#notes', false));
    }

    public function test_excludes_win_and_includes_only_pick_files(): void
    {
        $patterns = new Patterns(['*.yml'], ['secret.yml', 'cache/']);

        $this->assertTrue($patterns->allows('config.yml', false));
        $this->assertFalse($patterns->allows('secret.yml', false));
        $this->assertFalse($patterns->allows('server.jar', false));
        $this->assertTrue($patterns->allows('plugins', true));
        $this->assertFalse($patterns->allows('plugins/cache', true));
        $this->assertTrue((new Patterns)->allows('anything', false));
    }

    public function test_input_is_cleaned_and_bad_patterns_are_refused(): void
    {
        $this->assertSame(['*.log', 'cache/'], Patterns::clean([' *.log ', '', 'cache/', '*.log']));
        $this->assertNull(Patterns::clean(["bad\npattern"]));
        $this->assertNull(Patterns::clean(['back\\slash']));
        $this->assertNull(Patterns::clean([str_repeat('a', 256)]));
        $this->assertNull(Patterns::clean([5]));
        $this->assertNull(Patterns::clean(array_map(static fn (int $i): string => "*.{$i}", range(1, 21))));
    }

    public function test_the_tree_skips_excluded_entries_and_only_creates_folders_without_includes(): void
    {
        $entries = [new Entry('logs', true, 0), new Entry('world', true, 0), new Entry('a.log', false, 5), new Entry('server.properties', false, 9)];

        $excluded = Tree::expand('', $entries, [], new Patterns([], ['logs/', '*.log']));
        $this->assertSame(['world'], $excluded['folders']);
        $this->assertSame(['world'], $excluded['create']);
        $this->assertSame([['path' => 'server.properties', 'size' => 9]], $excluded['files']);
        $this->assertSame([], $excluded['skipped']);

        $included = Tree::expand('', $entries, [], new Patterns(['*.properties']));
        $this->assertSame(['logs', 'world'], $included['folders']);
        $this->assertSame([], $included['create']);
        $this->assertSame([['path' => 'server.properties', 'size' => 9]], $included['files']);

        $selected = Tree::expand('', $entries, ['a.log', 'gone.txt'], new Patterns([], ['*.log']));
        $this->assertSame([], $selected['files']);
        $this->assertSame([['path' => 'gone.txt', 'reason' => 'It no longer exists.']], $selected['skipped']);
    }
}
