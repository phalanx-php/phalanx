<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Closure;

interface TaskMiddleware
{
    /**
     * Wrap task execution. Inspect $task for behavioral interfaces and decide
     * whether to wrap $next($scope) in retry/timeout/etc., or just call
     * $next($scope) and return its result.
     *
     * `$next` MUST be invoked with an ExecutionScope. Middleware that creates a
     * child scope (TimeoutMiddleware via $scope->timeout) is responsible for
     * passing that child scope to $next so the task body honors the new
     * cancellation token. Pass-through middleware (Trace) forwards the same
     * scope it received.
     *
     * @param Closure(ExecutionScope): mixed $next
     */
    public function handle(Scopeable|Executable|Closure $task, ExecutionScope $scope, Closure $next): mixed;
}
