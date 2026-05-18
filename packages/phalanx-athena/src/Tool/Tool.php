<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

use Phalanx\Athena\Effect\Context as EffectContext;
use Phalanx\Athena\Effect\Outcome as EffectOutcome;
use Phalanx\Scope\TaskScope;

interface Tool
{
    public function __invoke(EffectContext $ctx, TaskScope $scope): EffectOutcome;
}
