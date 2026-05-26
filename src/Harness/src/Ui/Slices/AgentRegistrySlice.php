<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

use InvalidArgumentException;

class AgentRegistrySlice
{
    /**
     * @param list<AgentSummary> $agents
     */
    public function __construct(
        private(set) array $agents = [],
        private(set) ?string $activeAgentId = null,
    ) {
    }

    public function register(AgentSummary $agent): self
    {
        foreach ($this->agents as $existing) {
            if ($existing->id === $agent->id) {
                throw new InvalidArgumentException(
                    sprintf('Agent with id "%s" is already registered.', $agent->id),
                );
            }
        }

        return new self(
            agents: [...$this->agents, $agent],
            activeAgentId: $this->activeAgentId,
        );
    }

    public function activate(string $agentId): self
    {
        foreach ($this->agents as $agent) {
            if ($agent->id === $agentId) {
                return new self(
                    agents: $this->agents,
                    activeAgentId: $agentId,
                );
            }
        }

        throw new InvalidArgumentException(
            sprintf('Agent with id "%s" is not registered.', $agentId),
        );
    }
}
