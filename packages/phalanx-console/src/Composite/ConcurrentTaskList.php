<?php

declare(strict_types=1);

namespace Phalanx\Console\Composite;

use Phalanx\ExecutionScope;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Style\Theme;
use Phalanx\Console\Widget\TaskList;
use Phalanx\Console\Widget\TaskState;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use React\EventLoop\Loop;

/**
 * Runs a named set of tasks concurrently with a live spinner display.
 *
 * Each task: Pending → Running → Success | Error. Individual failures do not
 * abort the batch — settle() is used for per-task error isolation.
 *
 * The render timer fires at $spinnerFps Hz while the fiber is suspended inside
 * settle(). Timer invariant: ALWAYS cancelled in the finally block before
 * run() returns. Missing this corrupts subsequent terminal output.
 *
 * Usage (from within a command fiber):
 *
 *   (new ConcurrentTaskList($scope, $output, $theme))
 *       ->add('a', 'Download', new DownloadFile($url))
 *       ->add('b', 'Parse',    new ParseData())
 *       ->run();
 */
final class ConcurrentTaskList
{
    /** @var array<string, array{name: string, task: Executable|Scopeable}> */
    private array $tasks = [];

    public function __construct(
        private readonly ExecutionScope $scope,
        private readonly StreamOutput $output,
        private readonly Theme $theme,
        private readonly int $spinnerFps = 10,
    ) {}

    public function add(string $id, string $name, Executable|Scopeable $task): self
    {
        $clone             = clone $this;
        $clone->tasks[$id] = ['name' => $name, 'task' => $task];

        return $clone;
    }

    public function run(): void
    {
        if ($this->tasks === []) {
            return;
        }

        $taskList    = $this->buildTaskList();
        $spinnerTick = 0;
        $output      = $this->output;

        foreach (array_keys($this->tasks) as $id) {
            $taskList->setState($id, TaskState::Running);
        }

        $timer = Loop::addPeriodicTimer(
            1.0 / $this->spinnerFps,
            static function () use ($taskList, &$spinnerTick, $output): void {
                $spinnerTick++;
                $output->update($taskList->render($spinnerTick));
            },
        );

        $output->update($taskList->render(0));

        try {
            $bag = $this->scope->settle(
                array_map(static fn(array $def): Executable|Scopeable => $def['task'], $this->tasks),
            );

            foreach (array_keys($this->tasks) as $id) {
                if ($bag->isOk($id)) {
                    $taskList->setState($id, TaskState::Success);
                } else {
                    $taskList->setState($id, TaskState::Error, $bag->errors[$id]?->getMessage());
                }
            }
        } finally {
            Loop::cancelTimer($timer);
            $output->persist($taskList->render($spinnerTick));
        }
    }

    private function buildTaskList(): TaskList
    {
        $list = new TaskList($this->theme);
        foreach ($this->tasks as $id => $def) {
            $list->add($id, $def['name']);
        }

        return $list;
    }
}
