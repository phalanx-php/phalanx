<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

interface WorkerDispatch
{
    public function dispatch(
        WorkerTask $task,
        TaskScope&TaskExecutor $scope,
        CancellationToken $token,
    ): mixed;

    public function shutdown(): void;
}
