<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\State;

final class RuntimeSlice
{
    public function __construct(
        private(set) ?string $sessionId = null,
        private(set) bool $replaying = false,
        private(set) ?string $health = null,
    ) {
        if ($this->sessionId !== null && trim($this->sessionId) === '') {
            throw new \InvalidArgumentException('Runtime session id cannot be empty.');
        }

        if ($this->health !== null && trim($this->health) === '') {
            throw new \InvalidArgumentException('Runtime health cannot be empty.');
        }
    }

    public function update(
        ?string $sessionId = null,
        ?bool $replaying = null,
        ?string $health = null,
    ): self {
        return new self(
            sessionId: $sessionId ?? $this->sessionId,
            replaying: $replaying ?? $this->replaying,
            health: $health ?? $this->health,
        );
    }
}
