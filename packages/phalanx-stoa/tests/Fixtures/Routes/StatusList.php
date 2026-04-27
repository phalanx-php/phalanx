<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final class StatusList implements Scopeable
{
    public function __invoke(Scope $scope): string
    {
        return 'list';
    }
}
