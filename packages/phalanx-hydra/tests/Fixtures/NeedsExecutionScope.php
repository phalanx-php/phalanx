<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Fixtures;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Worker\WorkerTask;

final class NeedsExecutionScope implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __invoke(ExecutionScope $scope): string
    {
        return 'unreachable';
    }
}
