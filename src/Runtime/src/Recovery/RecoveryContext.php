<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Phalanx\Mark\Mark;
use Throwable;

final class RecoveryContext
{
    public function __construct(
        private(set) int $attempt,
        private(set) Mark $elapsed,
        private(set) ?Mark $remainingDeadline,
        private(set) ?Throwable $error,
        private(set) string $taskName,
        private(set) RecoveryPlan $plan,
    ) {
    }

    public function continue(): RecoveryDecision
    {
        return new RecoveryDecision(RecoveryAction::Continue);
    }

    public function retry(?Mark $delay = null): RecoveryDecision
    {
        return new RecoveryDecision(RecoveryAction::Retry, delay: $delay);
    }

    public function delay(Mark $duration): RecoveryDecision
    {
        return new RecoveryDecision(RecoveryAction::Delay, delay: $duration);
    }

    public function poll(?Mark $interval = null): RecoveryDecision
    {
        return new RecoveryDecision(RecoveryAction::Poll, delay: $interval);
    }

    public function cancel(): RecoveryDecision
    {
        return new RecoveryDecision(RecoveryAction::Cancel);
    }

    public function fail(?Throwable $error = null): RecoveryDecision
    {
        return new RecoveryDecision(RecoveryAction::Fail, error: $error ?? $this->error);
    }
}
