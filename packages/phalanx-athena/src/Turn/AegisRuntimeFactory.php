<?php

declare(strict_types=1);

namespace Phalanx\Athena\Turn;

use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Runtime\Aegis\Runtime as AegisRuntime;
use Phalanx\Scope\TaskScope;

final class AegisRuntimeFactory implements RuntimeFactory
{
    public function __invoke(TaskScope $scope): Runtime
    {
        return new AegisRuntime($scope);
    }
}
