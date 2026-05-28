<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Supervisor;

use Phalanx\Agentic\AgentSession\AgentSessionRegistry;
use Phalanx\Scope\Scope;

final class GlobalOversightFeed
{
    public function __construct(
        private readonly AgentSessionRegistry $registry,
        private readonly ConversationSupervisor $supervisor,
    ) {}

    public function render(Scope $scope): array
    {
        $state = $this->supervisor->getGlobalFeed();
        $feed = [
            'workspace' => $state->workspace(),
            'synthesis' => $state->synthesis(),
            'feed'      => $state->feed(),
        ];
        $feed['sessions'] = array_map(
            static fn($s) => [
                'id'     => $s->getConfig()->sessionId,
                'status' => $s->getState()->status,
                'agent'  => $s->getConfig()->agentClass,
            ],
            $this->registry->all()
        );
        return $feed;
    }
}
