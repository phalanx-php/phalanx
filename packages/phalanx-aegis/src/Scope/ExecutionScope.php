<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Phalanx\Scope\Stream\StreamContext;

interface ExecutionScope extends TaskScope, TaskExecutor, StreamContext
{
    public function withAttribute(string $key, mixed $value): ExecutionScope;
}
