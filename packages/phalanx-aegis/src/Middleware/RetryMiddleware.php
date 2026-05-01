<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Retryable;
use Phalanx\Task\Scopeable;
use Closure;

class RetryMiddleware implements TaskMiddleware
{
    public function handle(Scopeable|Executable|Closure $task, ExecutionScope $scope, Closure $next): mixed
    {
        if (!$task instanceof Retryable) {
            return $next($scope);
        }
        return $scope->retry(static fn(ExecutionScope $s): mixed => $next($s), $task->retryPolicy());
    }
}
