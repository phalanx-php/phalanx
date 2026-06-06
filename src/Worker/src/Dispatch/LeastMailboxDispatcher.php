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

final class LeastMailboxDispatcher implements Dispatcher
{
    /**
     * @param list<Worker> $workers
     */
    public function __construct(
        private readonly array $workers,
    ) {
    }

    public function dispatch(TaskScope&TaskExecutor $scope, TaskRequest $task, CancellationToken $token): mixed
    {
        if (count($this->workers) === 0) {
            throw new RuntimeException('No workers available');
        }

        $bestWorker = null;
        $bestSize = PHP_INT_MAX;

        foreach ($this->workers as $worker) {
            if ($worker->state === WorkerState::Crashed || $worker->state === WorkerState::Draining) {
                continue;
            }

            $size = $worker->mailboxSize();

            if ($size < $bestSize) {
                $bestSize = $size;
                $bestWorker = $worker;
            }
        }

        if ($bestWorker === null) {
            throw new RuntimeException('All workers unavailable');
        }

        return $bestWorker->send($scope, $task, $token);
    }
}
