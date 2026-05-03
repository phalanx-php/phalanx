<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * Read-only projection of a TaskRun for diagnostic surfaces (task tree
 * dumps, leak reports, error context). Snapshots are detached from the
 * ledger — taking one is safe to do from any coroutine without holding a
 * reference that prevents GC of the underlying run.
 *
 * Powers `Supervisor::tree()` and the future `phalanx doctor` /
 * `phalanx ps` style introspection commands.
 */
class TaskRunSnapshot
{
    /**
     * @param list<string> $childIds
     * @param list<array{domain: string, key: string, mode: string, acquiredAt: float}> $leases
     */
    public function __construct(
        protected(set) string $id,
        protected(set) string $name,
        protected(set) ?string $parentId,
        protected(set) DispatchMode $mode,
        protected(set) RunState $state,
        protected(set) ?WaitReason $currentWait,
        protected(set) array $childIds,
        protected(set) array $leases,
        protected(set) float $startedAt,
        protected(set) ?float $endedAt,
    ) {
    }

    public function elapsed(): float
    {
        $end = $this->endedAt ?? microtime(true);

        return $end - $this->startedAt;
    }
}
