<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Capstone;

use Phalanx\Theatron\Demos\Capstone\Slice\TaskBoardSlice;
use Phalanx\Theatron\Demos\Capstone\Slice\TaskEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskBoardSliceTest extends TestCase
{
    #[Test]
    public function add_task_appends_to_list(): void
    {
        $slice = new TaskBoardSlice();
        $task = new TaskEntry(id: 't1', title: 'Analyze', assignedTo: 'researcher', status: 'active');

        $updated = $slice->addTask($task);

        self::assertCount(1, $updated->tasks);
        self::assertSame('Analyze', $updated->tasks[0]->title);
        self::assertCount(0, $slice->tasks);
    }

    #[Test]
    public function update_status_changes_matching_task(): void
    {
        $slice = new TaskBoardSlice([
            new TaskEntry(id: 't1', title: 'A', assignedTo: 'a', status: 'active'),
            new TaskEntry(id: 't2', title: 'B', assignedTo: 'b', status: 'active'),
        ]);

        $updated = $slice->updateStatus('t1', 'completed');

        self::assertSame('completed', $updated->tasks[0]->status);
        self::assertSame('active', $updated->tasks[1]->status);
    }

    #[Test]
    public function update_status_preserves_unmatched_tasks(): void
    {
        $slice = new TaskBoardSlice([
            new TaskEntry(id: 't1', title: 'A', assignedTo: 'a', status: 'pending'),
        ]);

        $updated = $slice->updateStatus('nonexistent', 'completed');

        self::assertSame('pending', $updated->tasks[0]->status);
    }

    #[Test]
    public function slice_key_is_capstone_tasks(): void
    {
        self::assertSame('capstone.tasks', (new TaskBoardSlice())->key);
    }
}
