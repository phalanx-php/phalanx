<?php

declare(strict_types=1);

namespace Phalanx\Stream\Contract;

use Closure;
use Phalanx\Suspendable;

interface StreamContext extends Suspendable
{
    public function throwIfCancelled(): void;

    public function onDispose(Closure $callback): void;
}
