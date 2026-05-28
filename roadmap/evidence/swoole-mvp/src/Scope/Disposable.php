<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Scope;

interface Disposable
{
    public function onDispose(\Closure $callback): void;
}
