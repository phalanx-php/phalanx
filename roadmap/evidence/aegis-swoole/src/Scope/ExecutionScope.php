<?php

declare(strict_types=1);

namespace AegisSwoole\Scope;

use AegisSwoole\Scope\Stream\StreamContext;

interface ExecutionScope extends TaskScope, TaskExecutor, StreamContext
{
    public function withAttribute(string $key, mixed $value): ExecutionScope;
}
