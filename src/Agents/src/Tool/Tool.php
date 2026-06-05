<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tool;

use Phalanx\Agents\Effect\Context as EffectContext;
use Phalanx\Agents\Effect\Outcome as EffectOutcome;
use Phalanx\Scope\TaskScope;

interface Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome;
}
