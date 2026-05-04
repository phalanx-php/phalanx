<?php

declare(strict_types=1);

namespace Acme\ArchonDemo\Concurrency\Stages;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Wraps an inner Executable with $scope->timeout. A ship-stage regression
 * that stalls the upload is caught at the scope boundary instead of holding
 * the whole batch open.
 */
final class TimeoutStage implements Executable
{
    public function __construct(
        private readonly Executable $inner,
        private readonly float $seconds,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return $scope->timeout($this->seconds, $this->inner);
    }
}
