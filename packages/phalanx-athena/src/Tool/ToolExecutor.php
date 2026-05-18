<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

use Phalanx\Athena\Effect\Context;
use Phalanx\Athena\Effect\Executor;
use Phalanx\Athena\Effect\Outcome;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Scope\TaskScope;

final class ToolExecutor implements Executor
{
    public function __construct(
        private(set) ToolRegistry $registry,
    ) {
    }

    public function __invoke(TaskScope $scope, Requested $request, Context $context): Outcome
    {
        $scope->throwIfCancelled();

        return $this->registry->invoke($scope, $request->effectId, $context, $request->arguments);
    }
}
