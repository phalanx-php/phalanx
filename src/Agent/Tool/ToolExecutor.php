<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tool;

use Phalanx\Agent\Effect\Context;
use Phalanx\Agent\Effect\Executor;
use Phalanx\Agent\Effect\Outcome;
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
