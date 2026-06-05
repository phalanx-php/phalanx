<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Fixtures\Commands;

use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

final class FailingExitCommand implements Scopeable
{
    public function __invoke(Scope $scope): int
    {
        return 7;
    }
}
