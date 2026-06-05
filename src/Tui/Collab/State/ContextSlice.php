<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\State;

final class ContextSlice
{
    public function __construct(
        private(set) int $pressure = 0,
        private(set) ?string $activeFocus = null,
    ) {
        if ($this->pressure < 0) {
            throw new \InvalidArgumentException('Context pressure cannot be negative.');
        }

        if ($this->activeFocus !== null && trim($this->activeFocus) === '') {
            throw new \InvalidArgumentException('Context active focus cannot be empty.');
        }
    }

    public function update(
        ?int $pressure = null,
        ?string $activeFocus = null,
    ): self {
        return new self(
            pressure: $pressure ?? $this->pressure,
            activeFocus: $activeFocus ?? $this->activeFocus,
        );
    }
}
