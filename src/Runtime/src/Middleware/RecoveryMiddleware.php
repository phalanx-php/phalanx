<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Closure;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryRunner;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class RecoveryMiddleware implements TaskMiddleware
{
    private RecoveryRunner $runner;

    public function __construct()
    {
        $this->runner = new RecoveryRunner();
    }

    public function handle(Scopeable|Executable|Closure $task, ExecutionScope $scope, Closure $next): mixed
    {
        $plan = $task instanceof Recoverable ? $task->recovery : null;

        if ($plan === null || $plan->isNone()) {
            return $next($scope);
        }

        return $this->runner->run(
            $plan,
            static fn(ExecutionScope $child): mixed => $next($child),
            $scope,
        );
    }
}
