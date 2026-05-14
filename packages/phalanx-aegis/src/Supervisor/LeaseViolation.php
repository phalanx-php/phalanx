<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use RuntimeException;

/**
 * Thrown by Supervisor::registerLease() when a lease registration would
 * violate a Pool & Scope Discipline invariant. Each violation has a PHX
 * code that maps to a specific section of the framework documentation.
 *
 * PHX-POOL-001  Nested acquire from the same pool by the same TaskRun.
 *               The first acquire's connection is still held; reentrant
 *               acquire would either deadlock (pool size 1) or amplify
 *               connection use beyond what the bounded pool reserves
 *               for this task.
 *
 * PHX-POOL-002  Pool lease held across a worker dispatch boundary. The
 *               connection is process-local; the worker child cannot
 *               use it. Release before inWorker(), or perform the IO
 *               in the parent.
 *
 * PHX-TXN-001   External IO attempted while holding a transaction
 *               lease. If the transaction commits and the IO failed,
 *               retries become a correctness problem.
 *
 * PHX-LOCK-001  Lock acquisition order would deadlock. Multi-key
 *               acquire must canonical-sort keys before acquisition;
 *               an unsorted acquire is rejected when the supervisor
 *               detects a circular dependency in the acquire graph.
 *
 * PHX-LEASE-001 A terminal TaskRun was reaped while still holding
 *               leases. Indicates a missing release in the lease
 *               owner's code path. The supervisor releases them
 *               defensively but emits this code so the leak is visible.
 */
final class LeaseViolation extends RuntimeException
{
    public function __construct(
        public readonly string $phxCode,
        public readonly string $detail,
        public readonly ?string $runId = null,
        public readonly ?string $runName = null,
        public readonly ?Lease $offending = null,
    ) {
        $context = $runId !== null && $runName !== null ? " (task '{$runName}', run {$runId})" : '';
        parent::__construct("[{$phxCode}] {$detail}{$context}");
    }
}
