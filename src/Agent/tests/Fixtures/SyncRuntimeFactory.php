<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Fixtures;

use Phalanx\Agent\Turn\RuntimeFactory;
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
