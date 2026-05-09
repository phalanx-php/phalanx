<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\SupervisedConcurrency\Stages;

use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Wraps an inner Executable with $scope->retry. Used by the deploy command to
 * give specific stages a retry budget without complicating ConcurrentTaskList.
 */
final class RetryStage implements Executable
{
    public function __construct(
        private readonly Executable $inner,
        private readonly RetryPolicy $policy,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return $scope->retry($this->inner, $this->policy);
    }
}
