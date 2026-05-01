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
 * PHX-POOL-001. Holding a PoolLease across a worker dispatch boundary =
 * PHX-POOL-002 (the connection is process-local; serializing the lease
 * crosses an unsafe boundary).
 */
final class PoolLease implements Lease
{
    public string $domain {
        get => $this->poolName;
    }

    public string $key {
        get => $this->connectionId;
    }

    public string $mode {
        get => 'shared';
    }

    public float $acquiredAt {
        get => $this->acquired;
    }

    public function __construct(
        public readonly string $poolName,
        public readonly string $connectionId,
        public readonly float $acquired = 0.0,
    ) {
    }

    public static function open(string $poolName, string $connectionId): self
    {
        return new self($poolName, $connectionId, microtime(true));
    }
}
