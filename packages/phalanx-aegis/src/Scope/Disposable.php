<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;

interface Disposable
{
    /** @param Closure(): void $callback */
    public function onDispose(Closure $callback): void;

    public function dispose(): void;
}
