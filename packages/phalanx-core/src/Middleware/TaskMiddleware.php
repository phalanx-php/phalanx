<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

interface TaskMiddleware
{
    /** @param callable(): mixed $next */
    public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed;
}
