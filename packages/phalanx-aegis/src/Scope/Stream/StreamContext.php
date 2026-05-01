<?php

declare(strict_types=1);

namespace Phalanx\Scope\Stream;

use Phalanx\Scope\Suspendable;
use Closure;

interface StreamContext extends Suspendable
{
    public function throwIfCancelled(): void;

    /** @param Closure(): void $callback */
    public function onDispose(Closure $callback): void;
}
