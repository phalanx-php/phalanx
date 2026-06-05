<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Apps;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Collab\Boundaries\BoundaryRunner;
use Phalanx\Tui\Collab\Plans\WorkPlanStatus;
use Phalanx\Tui\Collab\State\AgentHarnessStore;
use Phalanx\Tui\Collab\WorkContext;

final class AgentHarnessRuntime
{
    private bool $running = false;

    public function __construct(
        private BoundaryRunner $runner,
        private AgentHarnessStore $store,
    ) {
    }

    public function tick(TaskScope $scope): WorkPlanStatus
    {
        if ($this->running) {
            return $this->store->workPlan->plan->status;
        }

        $this->running = true;

        try {
            return ($this->runner)(new WorkContext($scope, $this->store));
        } finally {
            $this->running = false;
        }
    }
}
