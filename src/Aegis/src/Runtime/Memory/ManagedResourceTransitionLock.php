<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use Swoole\Lock;

/**
 * Single-use guard returned by ManagedResourceTransitionLocks::acquire().
 *
 * Releasing the guard unlocks the underlying stripe mutex, letting the next
 * caller acquire it. release() is idempotent; calling it more than once is a
 * no-op (guarding against double-unlock in overlapping cleanup paths).
 */
final class ManagedResourceTransitionLock
{
    private bool $released = false;

    public function __construct(
        private readonly Lock $lock,
    ) {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->lock->unlock();
    }
}
