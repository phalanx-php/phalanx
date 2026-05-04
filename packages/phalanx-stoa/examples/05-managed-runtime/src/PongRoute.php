<?php

declare(strict_types=1);

namespace Acme\StoaDemo\ManagedRuntime;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class PongRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): string
    {
        return 'pong';
    }
}
