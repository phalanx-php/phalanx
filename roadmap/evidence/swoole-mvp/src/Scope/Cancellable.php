<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Scope;

interface Cancellable
{
    public function isCancelled(): bool;

    public function throwIfCancelled(): void;
}
