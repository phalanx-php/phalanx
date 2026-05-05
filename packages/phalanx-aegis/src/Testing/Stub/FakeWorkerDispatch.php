<?php

declare(strict_types=1);

namespace Phalanx\Testing\Stub;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Worker\WorkerDispatch;

final class FakeWorkerDispatch implements WorkerDispatch
{
    /** @var list<Scopeable|Executable> */
    public private(set) array $dispatched = [];

    public private(set) int $dispatchCount = 0;

    public private(set) mixed $lastScope = null;

    public private(set) ?CancellationToken $lastToken = null;

    public function dispatch(Scopeable|Executable $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed
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
