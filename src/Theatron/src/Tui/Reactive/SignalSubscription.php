<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Reactive;

final class SignalSubscription
{
    public bool $isDisposed {
        get => $this->signal === null;
    }

    public function __construct(private ?Signal $signal, private int $subscriberId)
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
