<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Store\Slice;

final class SignalRegistrySlice implements Slice
{
    public string $key { get => 'theatron.devtools.signals'; }

    /** @param list<SignalSnapshot> $signals */
    public function __construct(
        private(set) array $signals = [],
    ) {
    }

    /** @param list<SignalSnapshot> $signals */
    public function withSignals(array $signals): self
    {
        return new self($signals);
    }
}
