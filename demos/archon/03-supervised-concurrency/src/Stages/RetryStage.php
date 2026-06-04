<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\SupervisedConcurrency\Stages;

use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Wraps an inner Executable and declares a RecoveryPlan. Used by the deploy
 * command to give specific stages a retry budget without complicating
 * ConcurrentTaskList.
 */
final class RetryStage implements Executable, Recoverable
{
    public function __construct(
        private readonly Executable $inner,
        private(set) RecoveryPlan $recovery,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return $scope->execute($this->inner);
    }
}
