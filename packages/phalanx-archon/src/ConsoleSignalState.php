<?php

declare(strict_types=1);

namespace Phalanx\Archon;

/** @internal */
final class ConsoleSignalState
{
    private ?ConsoleSignal $signal = null;

    public function record(ConsoleSignal $signal): void
    {
        $this->signal = $signal;
    }

    public function current(): ?ConsoleSignal
    {
        return $this->signal;
    }
}
