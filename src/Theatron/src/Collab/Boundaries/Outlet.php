<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Boundaries;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Collab\Events\RoutableEvent;

interface Outlet
{
    public function __invoke(RoutableEvent $event, TaskScope $scope): void;
}
