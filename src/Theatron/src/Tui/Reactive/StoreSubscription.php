<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Reactive;

final class StoreSubscription
{
    public bool $isDisposed {
        get => $this->store === null;
    }

    public function __construct(private ?Store $store, private int $subscriberId)
    {
    }

    public function dispose(): void
    {
        if ($this->store === null) {
            return;
        }

        $store = $this->store;
        $this->store = null;
        $store->unsubscribe($this->subscriberId);
    }
}
