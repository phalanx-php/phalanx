<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Harness\Boundary;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Harness\Event\RoutableEvent;

interface Outlet
{
    public function __invoke(RoutableEvent $event, TaskScope $scope): void;
}
