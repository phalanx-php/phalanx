<?php

declare(strict_types=1);

namespace Phalanx\Worker\Dispatch;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Worker\Protocol\TaskRequest;

interface Dispatcher
{
    public function dispatch(TaskScope&TaskExecutor $scope, TaskRequest $task, CancellationToken $token): mixed;
}
