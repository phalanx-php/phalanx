<?php

declare(strict_types=1);

namespace Convoy;

use Convoy\Task\Executable;
use Convoy\Task\Scopeable;

interface WorkerDispatch
{
    public function inWorker(Scopeable|Executable $task, ExecutionScope $scope): mixed;
}
