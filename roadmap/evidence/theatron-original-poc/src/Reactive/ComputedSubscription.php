<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactive;

final class ComputedSubscription
{
    public bool $isDisposed {
        get => $this->computed === null;
    }

    public function __construct(private ?Computed $computed, private readonly int $subscriberId)
    {
    }

    public function dispose(): void
    {
        if ($this->computed === null) {
            return;
        }

        $computed = $this->computed;
        $this->computed = null;
        $computed->unsubscribe($this->subscriberId);
    }
}
