<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * A claim on a finite resource held by a TaskRun. The supervisor's ledger
 * tracks lease lifecycle so violations are mechanically detectable:
 *
 *   PHX-POOL-001  nested acquire from same pool by the same TaskRun
 *   PHX-POOL-002  pool lease held across a worker dispatch boundary
 *   PHX-TXN-001   external IO attempted while holding a transaction lease
 *   PHX-LOCK-001  lock acquisition order would deadlock (canonical sort
 *                 of multi-key acquire detects circular ordering)
 *
 * Implementations:
 *   PoolLease         pool name + connection id + 'shared'
 *   TransactionLease  pool name + tx id + 'exclusive'
 *   LockLease         lock domain + lock key + 'read' | 'write'
 *
 * Domain identifies the resource family. Key uniquely identifies the
 * specific instance within the domain. Mode controls compatibility with
 * other leases on the same key (e.g. multiple read-locks coexist; a
 * write-lock excludes everything else on the same key).
 */
interface Lease
{
    public string $domain { get; }

    public string $key { get; }

    public string $mode { get; }

    public float $acquiredAt { get; }
}
