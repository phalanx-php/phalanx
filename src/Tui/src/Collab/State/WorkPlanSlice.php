<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\State;

use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkPlan;
use Phalanx\Tui\Collab\Plans\WorkResult;

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

    public function append(WorkItem ...$items): self
    {
        $plan = $this->plan;
        $plan->append(...$items);

        return new self($plan);
    }

    public function start(string $itemId): self
    {
        $plan = $this->plan;
        $plan->startItem($itemId);

        return new self($plan);
    }

    public function abort(string $reason): self
    {
        $plan = $this->plan;
        $plan->abort($reason);

        return new self($plan);
    }
}
