<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

interface WorkerDispatch
{
    public function dispatch(
        Scopeable|Executable $task,
        TaskScope&TaskExecutor $scope,
        CancellationToken $token,
    ): mixed;

    public function shutdown(): void;
}
