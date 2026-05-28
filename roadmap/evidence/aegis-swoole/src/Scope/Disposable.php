<?php

declare(strict_types=1);

namespace AegisSwoole\Scope;

use Closure;

interface Disposable
{
    /** @param Closure(): void $callback */
    public function onDispose(Closure $callback): void;

    public function dispose(): void;
}
