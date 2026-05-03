<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use OpenSwoole\Lock;

final class ManagedResourceTransitionLock
{
    private bool $released = false;

    public function __construct(private readonly Lock $lock)
    {
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
