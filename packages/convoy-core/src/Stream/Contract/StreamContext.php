<?php

declare(strict_types=1);

namespace Convoy\Stream\Contract;

use Closure;

interface StreamContext
{
    public function throwIfCancelled(): void;

    public function onDispose(Closure $callback): void;
}
