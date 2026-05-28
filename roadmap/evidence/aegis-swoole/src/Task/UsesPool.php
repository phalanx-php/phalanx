<?php

declare(strict_types=1);

namespace AegisSwoole\Task;

/**
 * Behavioral interface declaring that a task should be dispatched into a named
 * worker pool. WorkerDispatch reads poolName() and routes accordingly.
 */
interface UsesPool
{
    public function poolName(): string;
}
