<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Supervisor;

use Phalanx\Agentic\AgentSession\AgentSessionRegistry;
use Phalanx\Agentic\Supervisor\SupervisorState;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class ConversationSupervisor implements Executable
{
    private SupervisorState $state;

    public function __construct(
        private readonly string $workspace = 'global',
        private readonly ?AgentSessionRegistry $registry = null,
    ) {
        $this->state = SupervisorState::initial($workspace);
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $registry = $this->registry ?? $scope->service(AgentSessionRegistry::class);

        $sessions = [];
        foreach ($registry->all() as $session) {
            $s = $session->getState();
            $sessions[$session->getConfig()->sessionId] = [
                'status'     => $s->status(),
                'tokens'     => $s->tokens(),
                'lastSignal' => $s->lastSignal(),
            ];
        }

        $this->state = $this->state->withActiveSessions($sessions);

        // Emit a live update signal for any connected UI
        $scope->service(\Phalanx\Eidolon\Signal\SignalCollector::class)
            ->event('agent.oversight.updated', [
                'workspace'       => $this->state->workspace(),
                'active_sessions' => $this->state->activeSessions(),
                'synthesis'       => $this->state->synthesis(),
                'feed'            => $this->state->feed(),
            ]);

        return $this->state;
    }

    public function getGlobalFeed(): SupervisorState
    {
        return $this->state;
    }
}
