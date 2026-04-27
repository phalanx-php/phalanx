<?php

declare(strict_types=1);

namespace Phalanx\Task;

use Phalanx\ExecutionScope;
use SplPriorityQueue;
use WeakMap;

use function React\Async\async;
use function React\Promise\race;

final class TaskScheduler implements Executable
{
    /** @var SplPriorityQueue<int, array{task: Scopeable|Executable, index: int}> */
    private readonly SplPriorityQueue $queue;

    /** @var WeakMap<Scopeable|Executable, mixed> */
    private WeakMap $results;

    /** @var array<string, int> */
    private array $runningByPool = [];

    /**
     * @param (Scopeable|Executable)[] $tasks
     * @param array<string, int> $poolLimits Keyed by enum name
     */
    public function __construct(
        private readonly array $tasks,
        private readonly array $poolLimits = [],
        private readonly int $defaultLimit = 100,
    ) {
        $this->queue = new SplPriorityQueue();
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $this->results = new WeakMap();
    }

    private function processQueue(ExecutionScope $scope): void
    {
        $pending = [];
        while (!$this->queue->isEmpty() || !empty($pending)) {
            while (!$this->queue->isEmpty()) {
                /** @var array{task: Scopeable|Executable, index: int} $item */
                $item = $this->queue->top();
                $task = $item['task'];

                $poolKey = $task instanceof UsesPool
                    ? $task->pool->name
                    : 'default';

                $limit = $this->poolLimits[$poolKey] ?? $this->defaultLimit;
                $currentRunning = $this->runningByPool[$poolKey] ?? 0;

                if ($currentRunning >= $limit) {
                    break;
                }

                $this->queue->extract();
                $this->runningByPool[$poolKey] = $currentRunning + 1;

                $results = $this->results;
                $running = &$this->runningByPool;

                $pendingState = (object) ['done' => false];
                $pending[] = [
                    'pool' => $poolKey,
                    'state' => $pendingState,
                    'promise' => async(static function () use (
                        $scope,
                        $task,
                        $results,
                        &$running,
                        $poolKey,
                        $pendingState,
                    ): mixed {
                        try {
                            $result = $scope->execute($task);
                            $results[$task] = $result;
                            return $result;
                        } finally {
                            $running[$poolKey]--;
                            $pendingState->done = true;
                        }
                    })(),
                ];
            }

            if ($pending === []) {
                break;
            }

            $promises = array_column($pending, 'promise');
            $scope->await(race($promises));

            $pending = array_values(array_filter(
                $pending,
                static fn($item): bool => !$item['state']->done,
            ));
        }
    }

    /** @return array<int|string, mixed> */
    public function __invoke(ExecutionScope $scope): array
    {
        foreach ($this->tasks as $index => $task) {
            $priority = $task instanceof HasPriority ? $task->priority : 0;
            $this->queue->insert(['task' => $task, 'index' => $index], $priority);
        }

        $this->processQueue($scope);

        $results = [];
        foreach ($this->tasks as $index => $task) {
            if (isset($this->results[$task])) {
                $results[$index] = $this->results[$task];
            }
        }

        return $results;
    }
}
