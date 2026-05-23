<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Handlers;

use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;

final class HandlerA implements Scopeable
{
    public function __invoke(Scope $scope): string
    {
        return 'a';
    }
}
