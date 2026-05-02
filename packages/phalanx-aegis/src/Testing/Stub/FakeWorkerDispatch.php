<?php

declare(strict_types=1);

namespace Phalanx\Testing\Stub;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Worker\WorkerDispatch;

final class FakeWorkerDispatch implements WorkerDispatch
{
    /** @var list<Scopeable|Executable> */
    public private(set) array $dispatched = [];

    public private(set) int $dispatchCount = 0;

    public function dispatch(Scopeable|Executable $task, CancellationToken $token): mixed
    {
        $this->dispatched[] = $task;
        $this->dispatchCount++;

        return null;
    }

    public function shutdown(): void
    {
    }
}
