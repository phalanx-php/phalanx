<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Boundaries;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Collab\Events\RoutableEvent;

interface Outlet
{
    public function __invoke(RoutableEvent $event, TaskScope $scope): void;
}
