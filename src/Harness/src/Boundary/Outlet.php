<?php

declare(strict_types=1);

namespace Phalanx\Harness\Boundary;

use Phalanx\Harness\Event\RoutableEvent;
use Phalanx\Scope\TaskScope;

interface Outlet
{
    public function __invoke(RoutableEvent $event, TaskScope $scope): void;
}
