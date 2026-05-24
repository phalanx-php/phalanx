<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Fixtures\Routes;

use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

final class StatusShow implements Scopeable
{
    public function __invoke(Scope $scope): string
    {
        return 'show';
    }
}
