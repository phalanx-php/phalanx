<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\SupervisedConcurrency\Stages;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Simulates uploading to the deployment target. The deploy command wraps
 * this in $scope->timeout(...) so a regression that hangs the upload is
 * caught at the scope boundary instead of stalling the batch.
 */
final class ShipStage implements Executable
{
    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay(1.60);

        return 'ship: deployed to staging.archon.local';
    }
}
