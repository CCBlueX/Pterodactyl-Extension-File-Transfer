<?php

declare(strict_types=1);

namespace FileTransfer\Tests\Unit;

use FileTransfer\Support\Budget;
use FileTransfer\Support\Cursor;
use FileTransfer\Support\Entry;
use FileTransfer\Support\Tree;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    public function test_steps_run_in_order(): void
    {
        $cursor = Cursor::start(true);
        $steps = [$cursor->step];

        while ($cursor->step !== 'done') {
            $cursor->advance();
            $steps[] = $cursor->step;
        }

        $this->assertSame(['wipe', 'scan', 'transfer', 'cleanup', 'done'], $steps);
        $this->assertSame('scan', Cursor::start(false)->step);
    }

    public function test_folders_are_listed_depth_first_in_name_order(): void
    {
        $cursor = Cursor::start(false);
        $listed = [];

        $tree = ['' => ['a', 'b'], 'a' => ['a/x'], 'a/x' => [], 'b' => []];

        while (($folder = $cursor->nextFolder()) !== null) {
            $listed[] = $folder;
            $cursor->addFolders($tree[$folder]);
        }

        $this->assertSame(['', 'a', 'a/x', 'b'], $listed);
    }

    public function test_cursor_survives_a_round_trip_and_resets_per_item(): void
    {
        $cursor = new Cursor('transfer', ['x'], 41, 1024, 'fetch', 1, 0.0);
        $cursor->fetched();
        $copy = Cursor::fromArray(json_decode((string) json_encode($cursor->toArray()), true));

        $this->assertSame('push', $copy->stage);
        $this->assertSame(0, $copy->offset);
        $this->assertSame(41, $copy->item);

        $copy->finish(42);
        $this->assertSame([42, 0, 'fetch', 0], [$copy->item, $copy->offset, $copy->stage, $copy->attempts]);

        $copy->finish(40);
        $this->assertSame(42, $copy->item, 'The cursor never moves backwards.');

        $this->assertSame('scan', Cursor::fromArray(['step' => 'bogus'])->step);
        $this->assertSame([''], Cursor::fromArray(null)->folders);
    }

    public function test_a_file_is_given_up_after_repeated_failures(): void
    {
        $cursor = Cursor::start(false);

        $this->assertTrue($cursor->retry());
        $this->assertTrue($cursor->retry());
        $this->assertFalse($cursor->retry());
    }

    public function test_speed_is_measured_from_large_files_only(): void
    {
        $cursor = Cursor::start(false);
        $cursor->measure(1000, 1.0);
        $this->assertSame(0.0, $cursor->rate);

        $cursor->measure(10_000_000, 1.0);
        $this->assertSame(10_000_000.0, $cursor->rate);

        $cursor->measure(20_000_000, 1.0);
        $this->assertSame(14_000_000.0, $cursor->rate);
    }

    public function test_budget_plans_whole_file_requests(): void
    {
        $now = 0.0;
        $budget = new Budget(40, static function () use (&$now): float {
            return $now;
        });

        $this->assertTrue($budget->fits(10_000_000_000, 1.0), 'The first file of a run always goes ahead.');
        $budget->worked();

        $this->assertTrue($budget->fits(1_000_000, 0));
        $this->assertFalse($budget->fits(50_000_000, 0), 'Large files wait for a fresh run until the speed is known.');
        $this->assertTrue($budget->fits(50_000_000, 10_000_000));

        $now = 30.0;
        $this->assertFalse($budget->fits(80_000_000, 10_000_000));
        $this->assertSame(10, $budget->timeout());

        $now = 36.0;
        $this->assertFalse($budget->fits(1, 10_000_000), 'Too little time is left for any request.');
        $this->assertSame(5, $budget->timeout());

        $now = 39.5;
        $this->assertTrue($budget->exhausted());
    }

    public function test_tree_expands_a_listing(): void
    {
        $entries = [
            new Entry('world', true, 0),
            new Entry('server.jar', false, 100),
            new Entry('latest.log', false, 7, true),
            new Entry('..', true, 0),
            new Entry('bad/name', false, 1),
        ];

        $this->assertSame([
            'files' => [['path' => 'plugins/server.jar', 'size' => 100]],
            'folders' => ['plugins/world'],
            'skipped' => [
                ['path' => 'plugins/..', 'reason' => 'The name is not supported.'],
                ['path' => 'plugins/bad/name', 'reason' => 'The name is not supported.'],
                ['path' => 'plugins/latest.log', 'reason' => 'Links and special files are skipped.'],
            ],
        ], Tree::expand('plugins', $entries));

        $selected = Tree::expand('', $entries, ['world', 'gone.txt']);
        $this->assertSame(['world'], $selected['folders']);
        $this->assertSame([], $selected['files']);
        $this->assertSame([['path' => 'gone.txt', 'reason' => 'It no longer exists.']], $selected['skipped']);
    }
}
