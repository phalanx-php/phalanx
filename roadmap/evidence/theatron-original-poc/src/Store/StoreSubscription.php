<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use Closure;

final class StoreSubscription
{
    private(set) bool $isDisposed = false;

    public function __construct(
        private readonly Closure $dispose,
    ) {
    }

    public function dispose(): void
    {
        if ($this->isDisposed) {
            return;
        }

        $this->isDisposed = true;
        ($this->dispose)();
    }
}
