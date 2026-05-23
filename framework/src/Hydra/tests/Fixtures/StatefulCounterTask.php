<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Worker\WorkerTask;

final class StatefulCounterTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    private static int $counter = 0;

    public function __invoke(Scope $scope): int
    {
        return ++self::$counter;
    }
}
