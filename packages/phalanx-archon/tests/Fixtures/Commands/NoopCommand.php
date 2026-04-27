<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Fixtures\Commands;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final class NoopCommand implements Scopeable
{
    public function __invoke(Scope $scope): int
    {
        return 0;
    }
}
