<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactive;

final class SignalSubscription
{
    public bool $isDisposed {
        get => $this->signal === null;
    }

    public function __construct(private ?Signal $signal, private readonly int $subscriberId)
    {
    }

    public function dispose(): void
    {
        if ($this->signal === null) {
            return;
        }

        $signal = $this->signal;
        $this->signal = null;
        $signal->unsubscribe($this->subscriberId);
    }
}
