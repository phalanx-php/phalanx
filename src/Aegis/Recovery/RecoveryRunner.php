<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Closure;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\TraceType;
use Throwable;

final class RecoveryRunner
{
    public function run(
        RecoveryPlan $plan,
        Scopeable|Executable|Closure $task,
        ExecutionScope $scope,
    ): mixed {
        if ($plan->isNone()) {
            return $scope->execute($task);
        }

        $started = Mark::now();
        $attempts = $plan->attempts ?? PHP_INT_MAX;
        $backoff = $plan->effectiveBackoff();
        $lastError = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $scope->throwIfCancelled();

            try {
                if ($plan->attemptTimeout !== null) {
                    return $scope->timeout($plan->attemptTimeout, $task);
                }

                return $scope->execute($task);
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e;

                if (!$plan->shouldRetry($e)) {
                    throw $e;
                }

                $elapsed = $started->elapsed();

                if ($plan->deadline !== null && $elapsed->gte($plan->deadline)) {
                    throw $e;
                }

                $callback = $plan->eventCallback();

                if ($callback !== null) {
                    $remainingDeadline = $plan->deadline?->minus($elapsed);
                    $event = new RecoveryEvent(
                        kind: RecoveryEventKind::AttemptFailed,
                        attempt: $attempt + 1,
                        elapsed: $elapsed,
                        remainingDeadline: $remainingDeadline,
                        error: $e,
                        taskName: $this->resolveTaskName($task),
                        plan: $plan,
                    );

                    $ctx = new RecoveryContext(
                        attempt: $attempt + 1,
                        elapsed: $elapsed,
                        remainingDeadline: $remainingDeadline,
                        error: $e,
                        taskName: $this->resolveTaskName($task),
                        plan: $plan,
                    );

                    $decision = $callback($event, $ctx);

                    if ($decision->action === RecoveryAction::Fail || $decision->action === RecoveryAction::Cancel) {
                        throw $decision->error ?? $e;
                    }

                    if ($decision->delay !== null) {
                        $scope->delay($decision->delay);

                        continue;
                    }
                }

                if ($attempt < $attempts - 1) {
                    $delay = $plan->pollInterval ?? $backoff?->delayFor($attempt) ?? Mark::zero();

                    if ($delay->isPositive()) {
                        $scope->delay($delay);
                    }
                }
            }
        }

        throw $lastError ?? new \RuntimeException('recovery: no attempts executed');
    }

    private function resolveTaskName(Scopeable|Executable|Closure $task): string
    {
        if ($task instanceof Scopeable || $task instanceof Executable) {
            return $task::class;
        }

        return 'closure';
    }
}
