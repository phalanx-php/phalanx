<?php

declare(strict_types=1);

namespace Phalanx\Ui\Signal;

interface Signal
{
    public SignalType $type { get; }

    public SignalPriority $priority { get; }

    /** @return array<string, mixed> */
    public function toArray(): array;
}
