<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final class StatusUsers implements Scopeable
{
    public function __invoke(Scope $scope): string
    {
        return 'users';
    }
}
