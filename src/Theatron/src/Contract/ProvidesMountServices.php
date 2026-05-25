<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Component\MountSystem;

interface ProvidesMountServices
{
    public function provideMountServices(MountSystem $mountSystem, ExecutionScope $scope): void;
}
