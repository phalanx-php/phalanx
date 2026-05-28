<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stream;

final class StreamSubscription
{
    private bool $disposed = false;

    /** @param \Closure(): void $disposer */
    public function __construct(
        private \Closure $disposer,
    ) {
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        ($this->disposer)();
    }
}
