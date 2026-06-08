<?php

declare(strict_types=1);

namespace Phalanx\Agents\Turn;

use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Runtime\Async\Runtime as ScopedRuntime;
use Phalanx\Scope\TaskScope;

final class ScopedRuntimeFactory implements RuntimeFactory
{
    public function __invoke(TaskScope $scope): Runtime
    {
        return new ScopedRuntime($scope);
    }
}
