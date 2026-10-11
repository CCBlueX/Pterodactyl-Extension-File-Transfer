<?php

declare(strict_types=1);

namespace FileTransfer\Tests\Unit;

use FileTransfer\Support\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    public function test_paths_are_normalised(): void
    {
        $this->assertSame('/', Paths::normalise(''));
        $this->assertSame('/', Paths::normalise('/'));
        $this->assertSame('/plugins/Essentials', Paths::normalise('plugins//Essentials/'));
        $this->assertSame('/world/region', Paths::normalise('/./world/./region'));
        $this->assertSame('/my files/ünïcode.txt', Paths::normalise('/my files/ünïcode.txt'));
    }

    public function test_paths_that_could_escape_are_refused(): void
    {
        $this->assertNull(Paths::normalise('/../etc/passwd'));
        $this->assertNull(Paths::normalise('/world/../../etc'));
        $this->assertNull(Paths::normalise('..'));
        $this->assertNull(Paths::normalise("/file\0.txt"));
        $this->assertNull(Paths::normalise("/line\nbreak"));
        $this->assertNull(Paths::normalise('/back\\slash'));
        $this->assertNull(Paths::normalise('/'.str_repeat('a', 4100)));
        $this->assertNull(Paths::normalise("/bad\xFF"));
    }

    public function test_names_must_be_single_segments(): void
    {
        $this->assertTrue(Paths::isName('server.properties'));
        $this->assertTrue(Paths::isName('.hidden'));
        $this->assertFalse(Paths::isName(''));
        $this->assertFalse(Paths::isName('.'));
        $this->assertFalse(Paths::isName('..'));
        $this->assertFalse(Paths::isName('a/b'));
        $this->assertFalse(Paths::isName("a\rb"));
        $this->assertSame(['a', 'b'], Paths::names(['a', 'b', 'a']));
        $this->assertNull(Paths::names(['a', '../b']));
        $this->assertNull(Paths::names(['a', 5]));
    }

    public function test_joined_paths_stay_inside_their_folder(): void
    {
        $this->assertSame('/target/world/level.dat', Paths::join('/target', 'world/level.dat'));
        $this->assertSame('/world', Paths::join('/', 'world'));
        $this->assertSame('/target', Paths::join('/target', ''));
        $this->assertNull(Paths::join('/target', '../other'));
        $this->assertNull(Paths::join('/target', 'a/../../b'));
    }

    public function test_relative_parent_and_inside(): void
    {
        $this->assertSame('b/c', Paths::relative('/a/b/c', '/a'));
        $this->assertSame('a/b', Paths::relative('/a/b', '/'));
        $this->assertSame('/a', Paths::parent('/a/b'));
        $this->assertSame('/', Paths::parent('/a'));
        $this->assertSame('/', Paths::parent('/'));
        $this->assertTrue(Paths::inside('/a/b', '/a'));
        $this->assertTrue(Paths::inside('/a', '/a'));
        $this->assertTrue(Paths::inside('/anything', '/'));
        $this->assertFalse(Paths::inside('/ab', '/a'));
    }
}
