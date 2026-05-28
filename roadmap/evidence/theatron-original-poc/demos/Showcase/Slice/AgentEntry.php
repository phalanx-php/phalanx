<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Slice;

final class AgentEntry
{
    public function __construct(
        private(set) string $id,
        private(set) string $name,
        private(set) string $role,
        private(set) string $provider,
        private(set) string $status = 'idle',
        private(set) int $tokens = 0,
    ) {
    }

    public function withStatus(string $status): self
    {
        return new self($this->id, $this->name, $this->role, $this->provider, $status, $this->tokens);
    }

    public function withTokens(int $tokens): self
    {
        return new self($this->id, $this->name, $this->role, $this->provider, $this->status, $tokens);
    }
}
