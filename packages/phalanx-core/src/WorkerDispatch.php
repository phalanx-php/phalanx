<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

interface WorkerDispatch
{
    public function inWorker(Scopeable|Executable $task, ExecutionScope $scope): mixed;
}
