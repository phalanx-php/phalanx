<?php

declare(strict_types=1);

namespace Phalanx\Athena\Effect;

use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Scope\TaskScope;

interface Executor
{
    public function __invoke(TaskScope $scope, Requested $request, Context $context): Outcome;
}
