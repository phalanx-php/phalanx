<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Slice;

use Phalanx\Theatron\Store\Slice;

final class AgentRegistrySlice implements Slice
{
    public string $key {
        get => 'capstone.agents';
    }

    /**
     * @param array<string, AgentInfo> $agents
     */
    public function __construct(
        private(set) array $agents = [],
    ) {
    }

    public function withStatus(string $agentId, string $status): self
    {
        if (!isset($this->agents[$agentId])) {
            return $this;
        }

        $agents = $this->agents;
        $agents[$agentId] = $agents[$agentId]->withStatus($status);

        return new self($agents);
    }
}
