<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Pipeline;

use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

interface BuildStage
{
    public string $name { get; }

    public string $description { get; }

    public function __invoke(TaskScope&TaskExecutor $scope, SpcBuildContext $context): StageResult;

    public function canSkip(SpcBuildContext $context): bool;
}
