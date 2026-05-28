<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Signal;

use Phalanx\Eidolon\Signal\Signal;
use Phalanx\Eidolon\Signal\SignalPriority;
use Phalanx\Eidolon\Signal\SignalType;

interface AgenticSignal extends Signal
{
    public function toArray(): array;

    public AgenticSignalType $type { get; }

    public SignalPriority $priority { get; }
}
