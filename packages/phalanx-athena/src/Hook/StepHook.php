<?php

declare(strict_types=1);

namespace Phalanx\Athena\Hook;

use Phalanx\Scope\TaskScope;

interface StepHook
{
    public function __invoke(TaskScope $scope, StepContext $context): StepHookResult;
}
