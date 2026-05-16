<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * A claim on a connection from a singleton pool, held by a TaskRun for the
 * duration of a checkout. Pool clients register the lease via
 * Supervisor::registerLease() at acquire time and release it on return.
 *
 * Domain: pool name (e.g. "postgres/main", "redis/cache").
 * Key:    connection identifier within the pool (object id, slot index).
 * Mode:   always 'shared' — pool checkouts are held exclusively by the
 *         leasing task; siblings hit the pool independently for their
 *         own connections, bounded by pool.size.
 *
 * Detection: nested acquire from the same pool by the same TaskRun =
 * DiagnosticCode::PoolNestedAcquire. Holding a PoolLease across a worker
 * dispatch boundary = DiagnosticCode::PoolCrossBoundary (the connection is
 * process-local; serializing the lease crosses an unsafe boundary).
 */
final class PoolLease implements Lease
{
    public string $mode {
        get => 'shared';
    }

    public function __construct(
        private(set) string $domain,
        private(set) string $key,
        private(set) float $acquiredAt = 0.0,
    ) {
    }

    public static function open(string $poolName, string $connectionId): self
    {
        return new self($poolName, $connectionId, microtime(true));
    }
}
