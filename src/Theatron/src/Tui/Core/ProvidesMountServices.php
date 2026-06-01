<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Tui\Core\MountSystem;

interface ProvidesMountServices
{
    public function provideMountServices(MountSystem $mountSystem, ExecutionScope $scope): void;
}
