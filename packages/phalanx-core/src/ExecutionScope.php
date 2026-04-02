<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Stream\Contract\StreamContext;

/**
 * Full execution scope with concurrency primitives, cancellation, and disposal.
 *
 * Inherits from TaskScope (execute, await, cancellation, disposal, service resolution)
 * and adds TaskExecutor (concurrent, race, map, timeout, retry, etc.).
 *
 * Orchestration tasks that compose multiple concurrent operations receive this type.
 * Leaf tasks and services that only need suspension should type-hint narrower interfaces
 * (Suspendable, TaskScope) in their constructors.
 */
interface ExecutionScope extends TaskScope, TaskExecutor, StreamContext
{
    public function withAttribute(string $key, mixed $value): ExecutionScope;
}
