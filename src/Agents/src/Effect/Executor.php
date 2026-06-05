<?php

declare(strict_types=1);

namespace Phalanx\Agents\Effect;

use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\Scope\TaskScope;

interface Executor
{
    public function __invoke(TaskScope $scope, Requested $request, Context $context): Outcome;
}
