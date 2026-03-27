<?php

declare(strict_types=1);

namespace Phalanx\Stream\Contract;

use Closure;

interface StreamContext
{
    public function throwIfCancelled(): void;

    public function onDispose(Closure $callback): void;
}
