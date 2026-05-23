<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use InvalidArgumentException;
use OpenSwoole\Lock;

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
        if (!$lock->lockwait($this->timeout)) {
            throw ManagedResourceLockTimeout::forResource($resourceId, $this->timeout);
        }

        return new ManagedResourceTransitionLock($lock);
    }

    public function destroy(): void
    {
        foreach ($this->locks as $lock) {
            $lock->destroy();
        }
    }

    private function stripe(string $resourceId): int
    {
        return abs(crc32($resourceId)) % $this->stripes;
    }
}
