<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

use Phalanx\Theatron\Collab\Plans\WorkPlan;
use Phalanx\Theatron\Collab\Plans\WorkResult;

final class WorkPlanSlice
{
    private(set) WorkPlan $plan;

    public function __construct(?WorkPlan $plan = null)
    {
        $this->plan = $plan ?? WorkPlan::empty();
    }

    public static function empty(): self
    {
        return new self(WorkPlan::empty());
    }

    public function fulfill(WorkResult $result): self
    {
        $plan = clone $this->plan;
        $plan->fulfill($result);

        return new self($plan);
    }
}
