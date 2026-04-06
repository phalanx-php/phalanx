<?php

declare(strict_types=1);

namespace Phalanx\Testing\Stub;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\WorkerDispatch;

final class FakeWorkerDispatch implements WorkerDispatch
{
    /** @var list<Scopeable|Executable> */
    public private(set) array $dispatched = [];

    public private(set) int $dispatchCount = 0;

    public function inWorker(Scopeable|Executable $task, ExecutionScope $scope): mixed
    {
        $this->dispatched[] = $task;
        $this->dispatchCount++;

        return $scope->executeFresh($task);
    }

    public function shutdown(): void
    {
    }
}
