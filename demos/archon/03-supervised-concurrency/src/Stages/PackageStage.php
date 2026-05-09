<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\SupervisedConcurrency\Stages;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Simulates packaging the build artifact for shipment.
 */
final class PackageStage implements Executable
{
    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay(0.90);

        return 'package: artifact.zip (4.2 MB)';
    }
}
