<?php

declare(strict_types=1);

namespace Phalanx\Agent\Hook;

use Phalanx\Scope\TaskScope;

interface StepHook
{
    public function __invoke(TaskScope $scope, StepContext $context): StepHookResult;
}
