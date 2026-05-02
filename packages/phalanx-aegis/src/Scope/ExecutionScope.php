<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Supervisor\TransactionLease;

interface ExecutionScope extends TaskScope, TaskExecutor, StreamContext
{
    public function withAttribute(string $key, mixed $value): ExecutionScope;

    /**
     * Run a body while a transaction lease is registered against the current
     * TaskRun. The body receives a narrowed scope that exposes local
     * coordination and services, but not fan-out or worker dispatch.
     *
     * @template T
     * @param Closure(TransactionScope): T $body
     * @return T
     */
    public function transaction(TransactionLease $lease, Closure $body): mixed;
}
