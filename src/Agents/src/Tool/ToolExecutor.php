<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tool;

use Phalanx\Agents\Effect\Context;
use Phalanx\Agents\Effect\Executor;
use Phalanx\Agents\Effect\Outcome;
use Phalanx\AiProviders\Cue\Effect\Requested;
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
