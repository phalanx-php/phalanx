<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Fixtures;

use Phalanx\Athena\Turn\RuntimeFactory;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Runtime\Sync\Runtime as SyncRuntime;
use Phalanx\Scope\TaskScope;

final class SyncRuntimeFactory implements RuntimeFactory
{
    public function __invoke(TaskScope $scope): Runtime
    {
        return new SyncRuntime();
    }
}
