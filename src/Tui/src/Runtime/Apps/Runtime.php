<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Apps;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Runtime\Boundaries\BoundaryRunner;
use Phalanx\Tui\Runtime\Plans\WorkPlanStatus;
use Phalanx\Tui\Runtime\State\Store;
use Phalanx\Tui\Runtime\WorkContext;

final class Runtime
{
    private bool $running = false;

    public function __construct(
        private BoundaryRunner $runner,
        private Store $store,
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
