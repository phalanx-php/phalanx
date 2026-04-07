<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Handlers;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final class HandlerB implements Scopeable
{
    public function __invoke(Scope $scope): string
    {
        return 'b';
    }
}
