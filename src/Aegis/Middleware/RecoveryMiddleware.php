<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Closure;
use Phalanx\Mark\Mark;
use Phalanx\Recovery\Backoff;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Recovery\RecoveryRunner;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Retryable;
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
        $plan = $this->resolvePlan($task);

        if ($plan === null || $plan->isNone()) {
            return $next($scope);
        }

        return $this->runner->run(
            $plan,
            static fn(ExecutionScope $child): mixed => $next($child),
            $scope,
        );
    }

    private function resolvePlan(Scopeable|Executable|Closure $task): ?RecoveryPlan
    {
        if ($task instanceof Recoverable) {
            return $task->recovery;
        }

        return self::legacyToPlan($task);
    }

    private static function legacyToPlan(mixed $task): ?RecoveryPlan
    {
        $plan = null;

        if ($task instanceof Retryable) {
            $policy = $task->retryPolicy;
            $plan = RecoveryPlan::defaultRetry(
                attempts: $policy->attempts,
                backoff: Backoff::fixed(Mark::ms($policy->baseDelayMs)),
            );
        }

        if ($task instanceof HasTimeout && $task->timeout > 0) {
            $timeout = Mark::s($task->timeout);
            $plan = ($plan ?? RecoveryPlan::failFast())->withAttemptTimeout($timeout);
        }

        return $plan;
    }
}
