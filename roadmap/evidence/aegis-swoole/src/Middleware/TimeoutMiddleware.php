<?php

declare(strict_types=1);

namespace AegisSwoole\Middleware;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\HasTimeout;
use AegisSwoole\Task\Scopeable;
use Closure;

class TimeoutMiddleware implements TaskMiddleware
{
    public function handle(Scopeable|Executable|Closure $task, ExecutionScope $scope, Closure $next): mixed
    {
        if (!$task instanceof HasTimeout) {
            return $next($scope);
        }
        return $scope->timeout(
            $task->timeoutSeconds(),
            static fn(ExecutionScope $child): mixed => $next($child),
        );
    }
}
