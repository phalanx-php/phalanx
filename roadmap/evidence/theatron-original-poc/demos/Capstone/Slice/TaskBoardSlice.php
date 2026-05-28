<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Slice;

use Phalanx\Theatron\Store\Slice;

final class TaskBoardSlice implements Slice
{
    public string $key {
        get => 'capstone.tasks';
    }

    /**
     * @param list<TaskEntry> $tasks
     */
    public function __construct(
        private(set) array $tasks = [],
    ) {
    }

    public function addTask(TaskEntry $task): self
    {
        return new self([...$this->tasks, $task]);
    }

    public function updateStatus(string $taskId, string $status): self
    {
        $tasks = array_map(
            static fn(TaskEntry $t) => $t->id === $taskId ? $t->withStatus($status) : $t,
            $this->tasks,
        );

        return new self($tasks);
    }
}
