<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;

class TimeoutMiddleware implements TaskMiddleware
{
    public function handle(Scopeable|Executable|Closure $task, ExecutionScope $scope, Closure $next): mixed
    {
        if (!$task instanceof HasTimeout) {
            return $next($scope);
        }
        return $scope->timeout(
            $task->timeout,
            static fn(ExecutionScope $child): mixed => $next($child),
        );
    }
}
