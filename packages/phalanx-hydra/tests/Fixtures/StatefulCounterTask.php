<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Fixtures;

use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

final class StatefulCounterTask implements Scopeable
{
    private static int $counter = 0;

    public function __invoke(Scope $scope): int
    {
        return ++self::$counter;
    }
}
