<?php

declare(strict_types=1);

namespace AegisSwoole\Scope\Stream;

use AegisSwoole\Scope\Suspendable;
use Closure;

interface StreamContext extends Suspendable
{
    public function throwIfCancelled(): void;

    /** @param Closure(): void $callback */
    public function onDispose(Closure $callback): void;
}
