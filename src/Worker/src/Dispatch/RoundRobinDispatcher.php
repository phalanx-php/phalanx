<?php

declare(strict_types=1);

namespace Phalanx\Worker\Dispatch;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Worker\Process\Worker;
use Phalanx\Worker\Process\WorkerState;
use Phalanx\Worker\Protocol\TaskRequest;
use RuntimeException;

final class RoundRobinDispatcher implements Dispatcher
{
    private int $index = 0;

    /**
     * @param list<Worker> $workers
     */
    public function __construct(
        private readonly array $workers,
    ) {
    }

    public function dispatch(TaskRequest $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $count = count($this->workers);

        if ($count === 0) {
            throw new RuntimeException('No workers available');
        }

        $attempts = 0;

        while ($attempts < $count) {
            $worker = $this->workers[$this->index];
            $this->index = ($this->index + 1) % $count;
            $attempts++;

            if ($worker->state !== WorkerState::Crashed && $worker->state !== WorkerState::Draining) {
                return $worker->send($task, $scope, $token);
            }
        }

        throw new RuntimeException('All workers unavailable');
    }
}
