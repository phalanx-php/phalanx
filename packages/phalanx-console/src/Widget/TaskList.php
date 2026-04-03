<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

use Phalanx\Console\Style\Theme;

enum TaskState
{
    case Pending;
    case Running;
    case Success;
    case Error;
    case Skipped;
}

/**
 * Single-shot task list renderer. No async — renders current state to a string.
 * Caller calls $output->update($taskList->render($tick)) on each tick/state change.
 *
 * ConcurrentTaskList (Phase 4) builds on top of this for async task tracking.
 */
final class TaskList
{
    /** @var array<string, array{name: string, state: TaskState, detail: string|null}> */
    private array $tasks = [];

    private Spinner $spinner;

    public function __construct(private readonly Theme $theme)
    {
        $this->spinner = new Spinner($theme, Spinner::BRAILLE);
    }

    public function add(string $id, string $name): void
    {
        $this->tasks[$id] = ['name' => $name, 'state' => TaskState::Pending, 'detail' => null];
    }

    public function setState(string $id, TaskState $state, ?string $detail = null): void
    {
        if (!isset($this->tasks[$id])) {
            return;
        }
        $this->tasks[$id]['state']  = $state;
        $this->tasks[$id]['detail'] = $detail;
    }

    public function render(int $spinnerTick = 0): string
    {
        $lines = [];
        foreach ($this->tasks as $task) {
            $icon  = $this->icon($task['state'], $spinnerTick);
            $name  = $task['name'];
            $detail = $task['detail'] !== null
                ? '  ' . $this->theme->muted->apply($task['detail'])
                : '';

            $lines[] = "  {$icon} {$name}{$detail}";
        }

        return implode("\n", $lines);
    }

    private function icon(TaskState $state, int $tick): string
    {
        return match ($state) {
            TaskState::Pending  => $this->theme->muted->apply('○'),
            TaskState::Running  => $this->spinner->frame($tick),
            TaskState::Success  => $this->theme->success->apply('✓'),
            TaskState::Error    => $this->theme->error->apply('✗'),
            TaskState::Skipped  => $this->theme->muted->apply('–'),
        };
    }
}
