<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Closure;
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
    /** @var array<string, TaskRun> */
    private array $runs = [];

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

    public function register(TaskRun $run): void
    {
        $this->runs[$run->id] = $run;
    }

    public function update(string $runId, Closure $patch): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run === null) {
            return;
        }
        $patch($run);
    }

    public function complete(string $runId, mixed $value): void
    {
        $this->update($runId, static function (TaskRun $run) use ($value): void {
            $run->state = RunState::Completed;
            $run->value = $value;
            $run->endedAt = microtime(true);
            $run->currentWait = null;
        });
    }

    public function fail(string $runId, Throwable $error): void
    {
        $this->update($runId, static function (TaskRun $run) use ($error): void {
            $run->state = RunState::Failed;
            $run->error = $error;
            $run->endedAt = microtime(true);
            $run->currentWait = null;
        });
    }

    public function cancel(string $runId): void
    {
        $this->update($runId, static function (TaskRun $run): void {
            $run->state = RunState::Cancelled;
            $run->endedAt = microtime(true);
            $run->currentWait = null;
        });
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
