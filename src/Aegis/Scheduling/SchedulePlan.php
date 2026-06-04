<?php

declare(strict_types=1);

namespace Phalanx\Scheduling;

use Closure;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * @internal Frozen snapshot of ScheduleBuilder state.
 */
final class SchedulePlan
{
    /** @param list<Scopeable|Executable|Closure> $tasks */
    public function __construct(
        private(set) string $mode,
        private(set) array $tasks,
        private(set) ?RecoveryPlan $recovery,
        private(set) TaskPriority $priority,
        private(set) ?int $maxConcurrency,
        private(set) ?LockKey $exclusive,
        private(set) ?SchedulePolicy $policy,
    ) {
    }
}
