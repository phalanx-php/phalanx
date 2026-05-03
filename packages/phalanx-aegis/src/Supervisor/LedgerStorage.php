<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * Backend for the supervisor's TaskRun ledger.
 */
interface LedgerStorage
{
    public function nextRunId(): string;

    public function nextScopeId(): string;

    public function registerScope(
        string $scopeId,
        ?string $parentScopeId,
        string $fqcn,
        int $coroutineId,
    ): void;

    public function disposeScope(string $scopeId): void;

    public function liveScopeCount(): int;

    public function register(TaskRun $run): void;

    public function addChild(string $parentRunId, string $childRunId): void;

    public function markRunning(string $runId): void;

    public function beginWait(string $runId, WaitReason $reason): void;

    public function clearWait(string $runId): void;

    public function addLease(string $runId, Lease $lease): void;

    public function releaseLease(string $runId, Lease $lease): void;

    public function complete(string $runId, mixed $value): void;

    public function fail(string $runId, \Throwable $error): void;

    public function cancel(string $runId): void;

    public function find(string $runId): ?TaskRun;

    public function snapshot(string $runId): ?TaskRunSnapshot;

    /**
     * Detached snapshot of the ledger. If $rootRunId is null, returns
     * every live (non-reaped) run. Otherwise returns the run plus its
     * transitive children.
     *
     * @return list<TaskRunSnapshot>
     */
    public function tree(?string $rootRunId = null): array;

    /**
     * Number of live (non-reaped) runs. Used by the dev-mode leaked-task
     * detector at scope disposal.
     */
    public function liveCount(): int;

    /**
     * Remove a terminal run from the ledger. Called by Supervisor::reap()
     * once dispose hooks have run and leases are released.
     */
    public function reap(string $runId): void;
}
