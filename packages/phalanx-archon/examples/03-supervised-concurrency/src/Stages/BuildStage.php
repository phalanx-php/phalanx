<?php

declare(strict_types=1);

namespace Phalanx\Archon\Examples\SupervisedConcurrency\Stages;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Pretends to compile a binary. The supervised delay yields back to the
 * reactor so the live task list spinner stays responsive.
 */
final class BuildStage implements Executable
{
    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay(1.20);

        return 'build: artifact-1.0.tar.gz';
    }
}
