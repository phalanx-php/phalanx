<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Widget;

use InvalidArgumentException;
use Phalanx\Archon\Console\Output\LiveRegionRenderer;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\TaskList;
use Phalanx\Archon\Console\Widget\TaskState;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Runs a named set of tasks concurrently with a live spinner display.
 *
 * Each task: Pending → Running → Success | Error. Individual failures do not
 * abort the batch — settle() is used for per-task error isolation.
 *
 * The render tick fires at $spinnerFps Hz on a scope-owned Subscription
 * while the fiber is suspended inside settle(). cancel() runs in the
 * finally block before the final persist; the scope would also dispose
 * the subscription on teardown, but eager cancellation prevents a
 * trailing render firing between the bag returning and persist.
 *
 * Usage (from within a command fiber):
 *
 *   new ConcurrentTaskList($scope, $output, $theme)
 *       ->add('a', 'Download', new DownloadFile($url))
 *       ->add('b', 'Parse', new ParseData())
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
        private readonly ?LiveRegionRenderer $renderer = null,
    ) {
        if ($this->spinnerFps <= 0) {
            throw new InvalidArgumentException('ConcurrentTaskList spinner FPS must be greater than zero.');
        }
    }

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
        $renderer    = $this->renderer ?? new LiveRegionRenderer($this->output);

        foreach (array_keys($this->tasks) as $id) {
            $taskList->setState($id, TaskState::Running);
        }

        $renderer->update($taskList->render(0));

        $subscription = $this->scope->periodic(
            1.0 / $this->spinnerFps,
            static function () use ($taskList, &$spinnerTick, $renderer): void {
                $spinnerTick++;
                $renderer->update($taskList->render($spinnerTick));
            },
        );

        try {
            $bag = $this->scope->settle(
                ...array_map(static fn(array $def): Executable|Scopeable => $def['task'], $this->tasks),
            );

            foreach (array_keys($this->tasks) as $id) {
                if ($bag->isOk($id)) {
                    $taskList->setState($id, TaskState::Success);
                } else {
                    $taskList->setState($id, TaskState::Error, $bag->errors[$id]->getMessage());
                }
            }
        } finally {
            $subscription->cancel();
            $renderer->settle($taskList->render($spinnerTick));
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
