<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Apps;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Collab\Boundaries\BoundaryRunner;
use Phalanx\Theatron\Collab\Plans\WorkPlanStatus;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\WorkContext;

final class CollabRuntime
{
    private bool $running = false;

    public function __construct(
        private BoundaryRunner $runner,
        private CollabStore $store,
    ) {
    }

    public function tick(TaskScope $scope): WorkPlanStatus
    {
        if ($this->running || !$this->runner->shouldRun()) {
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
