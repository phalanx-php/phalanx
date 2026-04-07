<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final class StatusShow implements Scopeable
{
    public function __invoke(Scope $scope): string
    {
        return 'show';
    }
}
