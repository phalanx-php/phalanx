<?php

declare(strict_types=1);

namespace Phalanx\Console\Runtime;

/** @internal */
final class SignalState
{
    private ?Signal $signal = null;

    public function record(Signal $signal): void
    {
        $this->signal = $signal;
    }

    public function current(): ?Signal
    {
        return $this->signal;
    }
}
