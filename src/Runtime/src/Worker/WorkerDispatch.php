<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

interface WorkerDispatch
{
    public function dispatch(
        TaskScope&TaskExecutor $scope,
        WorkerTask $task,
        CancellationToken $token,
    ): mixed;

    public function shutdown(): void;
}
