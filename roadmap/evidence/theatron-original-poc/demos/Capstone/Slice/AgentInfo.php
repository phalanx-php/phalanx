<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Slice;

final class AgentInfo
{
    public function __construct(
        private(set) string $id,
        private(set) string $name,
        private(set) string $role,
        private(set) string $status = 'offline',
    ) {
    }

    public function withStatus(string $status): self
    {
        return new self($this->id, $this->name, $this->role, $status);
    }
}
