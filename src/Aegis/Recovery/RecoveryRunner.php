<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\TraceType;
use RuntimeException;
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
        $taskName = self::resolveTaskName($task);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $scope->throwIfCancelled();

            try {
                return $plan->attemptTimeout !== null
                    ? $scope->timeout($plan->attemptTimeout, $task)
                    : $scope->execute($task);
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

                $delay = $this->resolveDelay($plan, $attempt, $elapsed, $e, $taskName, $backoff);

                if ($delay !== null && $delay->isPositive() && $attempt < $attempts - 1) {
                    $scope->trace()->log(
                        TraceType::Retry,
                        $taskName,
                        ['attempt' => $attempt + 1, 'delay_ms' => $delay->toMilliseconds(), 'error' => $e->getMessage()],
                    );

                    $scope->delay($delay);
                }
            }
        }

        throw $lastError ?? new RuntimeException('recovery: no attempts executed');
    }

    private function resolveDelay(
        RecoveryPlan $plan,
        int $attempt,
        Mark $elapsed,
        Throwable $error,
        string $taskName,
        ?Backoff $backoff,
    ): ?Mark {
        $callback = $plan->eventCallback();

        if ($callback === null) {
            return $plan->pollInterval ?? $backoff?->delayFor($attempt);
        }

        $remainingDeadline = $plan->deadline?->minus($elapsed);

        $event = new RecoveryEvent(
            kind: RecoveryEventKind::AttemptFailed,
            attempt: $attempt + 1,
            elapsed: $elapsed,
            remainingDeadline: $remainingDeadline,
            error: $error,
            taskName: $taskName,
            plan: $plan,
        );

        $ctx = new RecoveryContext(
            attempt: $attempt + 1,
            elapsed: $elapsed,
            remainingDeadline: $remainingDeadline,
            error: $error,
            taskName: $taskName,
            plan: $plan,
        );

        /** @var RecoveryDecision $decision */
        $decision = $callback($event, $ctx);

        if ($decision->action === RecoveryAction::Fail || $decision->action === RecoveryAction::Cancel) {
            throw $decision->error ?? $error;
        }

        return match ($decision->action) {
            RecoveryAction::Delay => $decision->delay,
            RecoveryAction::Poll => $decision->delay ?? $plan->pollInterval,
            RecoveryAction::Retry => $decision->delay ?? $backoff?->delayFor($attempt),
            RecoveryAction::Continue => null,
        };
    }

    private static function resolveTaskName(Scopeable|Executable|Closure $task): string
    {
        if ($task instanceof Scopeable || $task instanceof Executable) {
            return $task::class;
        }

        return 'closure';
    }
}
