<?php

declare(strict_types=1);

namespace Phalanx\Demos\Console\SupervisedConcurrency\Stages;

use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Simulates packaging the build artifact for shipment.
 */
final class PackageStage implements Executable
{
    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay(Mark::ms(900));

        return 'package: artifact.zip (4.2 MB)';
    }
}
