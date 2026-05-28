<?php

declare(strict_types=1);

namespace AegisSwoole\Middleware;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\Retryable;
use AegisSwoole\Task\Scopeable;
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
