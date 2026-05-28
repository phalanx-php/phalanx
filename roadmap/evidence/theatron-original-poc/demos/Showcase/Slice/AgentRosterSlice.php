<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Slice;

use Phalanx\Theatron\Store\Slice;

final class AgentRosterSlice implements Slice
{
    public string $key {
        get => 'showcase.roster';
    }

    /**
     * @param array<string, AgentEntry> $agents
     */
    public function __construct(
        private(set) array $agents = [],
    ) {
    }

    public function withStatus(string $agentId, string $status): self
    {
        $agents = $this->agents;
        if (isset($agents[$agentId])) {
            $agents[$agentId] = $agents[$agentId]->withStatus($status);
        }

        return new self($agents);
    }

    public function withTokens(string $agentId, int $add): self
    {
        $agents = $this->agents;
        if (isset($agents[$agentId])) {
            $agents[$agentId] = $agents[$agentId]->withTokens($agents[$agentId]->tokens + $add);
        }

        return new self($agents);
    }
}
