<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;

final class NeedsExecutionScope implements Scopeable
{
    public function __invoke(ExecutionScope $scope): string
    {
        return 'unreachable';
    }
}
