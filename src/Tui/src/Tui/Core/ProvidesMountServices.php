<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Tui\Tui\Core\MountSystem;

interface ProvidesMountServices
{
    public function provideMountServices(MountSystem $mountSystem, ExecutionScope $scope): void;
}
