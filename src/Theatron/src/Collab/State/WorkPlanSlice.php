<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

use Phalanx\Theatron\Collab\Plans\WorkPlan;
use Phalanx\Theatron\Collab\Plans\WorkResult;

final class WorkPlanSlice
{
    /** clone-backed: WorkPlan is mutable, so readers cannot mutate store state out of band. */
    private(set) WorkPlan $plan {
        get => clone $this->plan;
    }

    public function __construct(?WorkPlan $plan = null)
    {
        $this->plan = $plan === null ? WorkPlan::empty() : clone $plan;
    }

    public static function empty(): self
    {
        return new self(WorkPlan::empty());
    }

    public function fulfill(WorkResult $result): self
    {
        $plan = $this->plan;
        $plan->fulfill($result);

        return new self($plan);
    }
}
