<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

final class RuntimeSlice
{
    public function __construct(
        private(set) ?string $sessionId = null,
        private(set) bool $replaying = false,
        private(set) ?string $health = null,
    ) {
    }
}
