<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Fixtures;

use Phalanx\Agents\Turn\RuntimeFactory;
use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Runtime\Sync\Runtime as SyncRuntime;
use Phalanx\Scope\TaskScope;

final class SyncRuntimeFactory implements RuntimeFactory
{
    public function __invoke(TaskScope $scope): Runtime
    {
        return new SyncRuntime();
    }
}
