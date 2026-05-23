<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

final class StatusOk implements Scopeable
{
    public function __invoke(Scope $scope): string
    {
        return 'ok';
    }
}
