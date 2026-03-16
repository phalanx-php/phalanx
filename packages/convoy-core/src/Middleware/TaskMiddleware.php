<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Scopeable;

interface TaskMiddleware
{
    /** @param callable(): mixed $next */
    public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed;
}
