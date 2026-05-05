<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Dispatch;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Hydra\Protocol\TaskRequest;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

interface Dispatcher
{
    public function dispatch(TaskRequest $task, TaskScope&TaskExecutor $scope, CancellationToken $token): mixed;
}
