<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tool;

use Phalanx\Agent\Effect\Context as EffectContext;
use Phalanx\Agent\Effect\Outcome as EffectOutcome;
use Phalanx\Scope\TaskScope;

interface Tool
{
    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome;
}
