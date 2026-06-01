<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

final class ContextSlice
{
    public function __construct(
        private(set) int $pressure = 0,
        private(set) ?string $activeFocus = null,
    ) {
        if ($this->pressure < 0) {
            throw new \InvalidArgumentException('Context pressure cannot be negative.');
        }
    }
}
