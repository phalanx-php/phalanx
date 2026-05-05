<?php

declare(strict_types=1);

namespace Phalanx\Scope\Stream;

use Closure;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

interface StreamContext extends Scope, Suspendable
{
    public function throwIfCancelled(): void;

    /** @param Closure(): void $callback */
    public function onDispose(Closure $callback): void;
}
