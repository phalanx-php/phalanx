<?php

declare(strict_types=1);

namespace Phalanx;

use Closure;

interface Disposable
{
    public function onDispose(Closure $callback): void;

    public function dispose(): void;
}
