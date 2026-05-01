<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Closure;

/**
 * Backend for the supervisor's TaskRun ledger.
 *
 * The supervisor's logic is backend-agnostic. Two viable implementations:
 *
 *   InProcessLedger    PHP array on the supervisor object. Cheapest for
 *                      single-process workloads. Cooperative non-preemptive
 *                      scheduling makes mutations atomic between yield
 *                      points — no locking required.
 *
 *   SwooleTableLedger  Swoole\Table shared memory. Mandatory the moment
 *                      workers participate in the live ledger so the
 *                      parent and worker children both see TaskRuns in
 *                      real time. Constraints: fixed schema, fixed string
 *                      column widths, no nested arrays — the lease /
 *                      child-edge / wait-reason schema must accommodate
 *                      these limits when targeting this backend.
 *
 * Implementations are responsible for thread/coroutine safety as their
 * substrate requires. InProcessLedger relies on the cooperative scheduler;
 * SwooleTableLedger relies on Swoole\Table's atomic column ops plus
 * carefully-scoped read-modify-write through update().
 */
interface LedgerStorage
{
    public function register(TaskRun $run): void;

    /**
     * Atomically read-modify-write a run record. The closure receives the
     * live TaskRun (or null if the run is gone), mutates it in place, and
     * the implementation persists the result. Returning false from the
     * closure aborts the write.
     *
     * @param Closure(TaskRun): (void|bool) $patch
     */
    public function update(string $runId, Closure $patch): void;

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
