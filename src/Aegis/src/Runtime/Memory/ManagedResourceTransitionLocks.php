<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use InvalidArgumentException;
use Swoole\Lock;

/**
 * Striped cross-process mutexes guarding Swoole Table read-modify-write
 * sequences. Each stripe is a C-level Swoole\Lock(MUTEX) over shared memory.
 *
 * acquire() takes the stripe lock with a bounded wait: Swoole 6's
 * Lock::lock(timeout:) returns false when the wait expires, which we surface
 * as a ManagedResourceLockTimeout rather than blocking a worker indefinitely.
 */
final class ManagedResourceTransitionLocks
{
    /** @var list<Lock> */
    private array $locks = [];

    public function __construct(
        private readonly int $stripes,
        private readonly float $timeout,
    ) {
        if ($stripes < 1) {
            throw new InvalidArgumentException('stripes must be greater than zero.');
        }

        if ($timeout < 1.0) {
            throw new InvalidArgumentException('timeout must be greater than or equal to one.');
        }

        for ($i = 0; $i < $stripes; $i++) {
            $this->locks[] = new Lock(Lock::MUTEX);
        }
    }

    public function acquire(string $resourceId): ManagedResourceTransitionLock
    {
        $lock = $this->locks[$this->stripe($resourceId)];

        if (!$lock->lock(timeout: $this->timeout)) {
            throw ManagedResourceLockTimeout::forResource($resourceId, $this->timeout);
        }

        return new ManagedResourceTransitionLock($lock);
    }

    public function destroy(): void
    {
        // Swoole 6 locks free their shared-memory segment on __destruct;
        // dropping the references is the cleanup.
        $this->locks = [];
    }

    private function stripe(string $resourceId): int
    {
        return abs(crc32($resourceId)) % $this->stripes;
    }
}
