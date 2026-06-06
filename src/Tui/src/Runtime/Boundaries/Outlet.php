<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Boundaries;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Runtime\Events\RoutableEvent;

interface Outlet
{
    public function __invoke(TaskScope $scope, RoutableEvent $event): void;
}
