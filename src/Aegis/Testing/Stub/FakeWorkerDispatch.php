<?php

declare(strict_types=1);

namespace Phalanx\Testing\Stub;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Worker\WorkerDispatch;
use Phalanx\Worker\WorkerTask;

final class FakeWorkerDispatch implements WorkerDispatch
{
    /** @var list<WorkerTask> */
    private(set) array $dispatched = [];

    private(set) int $dispatchCount = 0;

    private(set) mixed $lastScope = null;

    private(set) ?CancellationToken $lastToken = null;

    public function dispatch(WorkerTask $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
    {
        $this->dispatched[] = $task;
        $this->dispatchCount++;
        $this->lastScope = $scope;
        $this->lastToken = $token;

        return null;
    }

    public function shutdown(): void
    {
    }
}
