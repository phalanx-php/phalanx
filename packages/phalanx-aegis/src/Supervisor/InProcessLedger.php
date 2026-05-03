<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Throwable;

/**
 * In-process LedgerStorage backend. PHP array keyed by run id.
 *
 * Coroutine safety: the OpenSwoole 26 substrate is cooperative and
 * non-preemptive on a single thread. State mutations between yield
 * points are atomic from any single coroutine's view, so the
 * read-modify-write inside update() needs no locking. This is the same
 * property that lets SingleflightGroup operate without a mutex.
 *
 * Cross-process visibility: none. Worker children running tasks in
 * separate processes do not see entries written here. For the
 * supervisor to track worker-child TaskRuns alongside parent runs in
 * real time, swap to SwooleTableLedger (shared memory) at construction.
 */
final class InProcessLedger implements LedgerStorage
{
    private int $runSeq = 0;

    private int $scopeSeq = 0;

    /** @var array<string, TaskRun> */
    private array $runs = [];

    /** @var array<string, bool> */
    private array $scopes = [];

    private static function project(TaskRun $run): TaskRunSnapshot
    {
        $leases = [];
        foreach ($run->leases as $lease) {
            $leases[] = [
                'domain' => $lease->domain,
                'key' => $lease->key,
                'mode' => $lease->mode,
                'acquiredAt' => $lease->acquiredAt,
            ];
        }

        return new TaskRunSnapshot(
            id: $run->id,
            name: $run->name,
            parentId: $run->parentId,
            mode: $run->mode,
            state: $run->state,
            currentWait: $run->currentWait,
            childIds: $run->childIds,
            leases: $leases,
            startedAt: $run->startedAt,
            endedAt: $run->endedAt,
        );
    }

    public function nextRunId(): string
    {
        return 'run-' . str_pad((string) ++$this->runSeq, 6, '0', STR_PAD_LEFT);
    }

    public function nextScopeId(): string
    {
        return 'scope-' . str_pad((string) ++$this->scopeSeq, 6, '0', STR_PAD_LEFT);
    }

    public function registerScope(
        string $scopeId,
        ?string $parentScopeId,
        string $fqcn,
        int $coroutineId,
    ): void {
        $this->scopes[$scopeId] = true;
    }

    public function disposeScope(string $scopeId): void
    {
        unset($this->scopes[$scopeId]);
    }

    public function liveScopeCount(): int
    {
        return count($this->scopes);
    }

    public function register(TaskRun $run): void
    {
        $this->runs[$run->id] = $run;
    }

    public function addChild(string $parentRunId, string $childRunId): void
    {
        $run = $this->runs[$parentRunId] ?? null;
        if ($run === null) {
            return;
        }
        $run->childIds[] = $childRunId;
    }

    public function markRunning(string $runId): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run !== null) {
            $run->state = RunState::Running;
        }
    }

    public function beginWait(string $runId, WaitReason $reason): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run !== null) {
            $run->state = RunState::Suspended;
            $run->currentWait = $reason;
        }
    }

    public function clearWait(string $runId): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run !== null && $run->state === RunState::Suspended) {
            $run->state = RunState::Running;
            $run->currentWait = null;
        }
    }

    public function addLease(string $runId, Lease $lease): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run !== null) {
            $run->leases[] = $lease;
        }
    }

    public function releaseLease(string $runId, Lease $lease): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run === null) {
            return;
        }

        foreach ($run->leases as $i => $held) {
            if ($held === $lease) {
                array_splice($run->leases, $i, 1);
                return;
            }
        }
    }

    public function complete(string $runId, mixed $value): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run === null) {
            return;
        }
        $run->state = RunState::Completed;
        $run->value = $value;
        $run->endedAt = microtime(true);
        $run->currentWait = null;
    }

    public function fail(string $runId, Throwable $error): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run === null) {
            return;
        }
        $run->state = RunState::Failed;
        $run->error = $error;
        $run->endedAt = microtime(true);
        $run->currentWait = null;
    }

    public function cancel(string $runId): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run === null) {
            return;
        }
        $run->state = RunState::Cancelled;
        $run->endedAt = microtime(true);
        $run->currentWait = null;
    }

    public function find(string $runId): ?TaskRun
    {
        return $this->runs[$runId] ?? null;
    }

    public function snapshot(string $runId): ?TaskRunSnapshot
    {
        $run = $this->runs[$runId] ?? null;
        return $run === null ? null : self::project($run);
    }

    public function tree(?string $rootRunId = null): array
    {
        if ($rootRunId === null) {
            return array_values(array_map(self::project(...), $this->runs));
        }

        $root = $this->runs[$rootRunId] ?? null;
        if ($root === null) {
            return [];
        }

        $out = [self::project($root)];
        foreach ($root->childIds as $childId) {
            foreach ($this->tree($childId) as $descendant) {
                $out[] = $descendant;
            }
        }
        return $out;
    }

    public function liveCount(): int
    {
        $live = 0;
        foreach ($this->runs as $run) {
            if (!$run->isTerminal()) {
                $live++;
            }
        }
        return $live;
    }

    public function reap(string $runId): void
    {
        unset($this->runs[$runId]);
    }
}
