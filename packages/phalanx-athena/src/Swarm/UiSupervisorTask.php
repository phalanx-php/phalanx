<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class UiSupervisorTask implements Executable
{
    /** @var array<string, mixed> */
    private array $state = [
        'agents' => [],
        'synthesis' => 'Swarm initializing...',
        'feed' => [],
    ];

    public function __construct(
        public readonly string $agentId,
        public readonly SwarmBus $bus,
        public readonly SwarmConfig $config,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $bus = $this->bus;
        $workspace = $this->config->workspace;

        foreach ($bus->subscribe([
            'workspace' => $workspace,
            'kinds' => [
                SwarmEventKind::Online,
                SwarmEventKind::SummaryUpdate,
                SwarmEventKind::BlackboardPost,
                SwarmEventKind::ClearanceRequested,
                SwarmEventKind::ClearanceGranted,
                SwarmEventKind::ClearanceDenied,
                SwarmEventKind::UiIntent,
            ],
        ])($scope) as $event) {
            $this->updateState($event);
            
            $this->bus->emit(new SwarmEvent(
                from: $this->agentId,
                kind: SwarmEventKind::UiRender,
                workspace: $this->config->workspace,
                session: $this->config->session,
                payload: $this->state
            ));
        }
        
        return null;
    }

    private function updateState(SwarmEvent $e): void
    {
        $id = $e->from;

        if (!isset($this->state['agents'][$id])) {
            $this->state['agents'][$id] = ['status' => 'Online', 'tokens' => 0];
        }

        if ($e->kind === SwarmEventKind::SummaryUpdate) {
            $this->state['synthesis'] = $e->payload['text'] ?? $this->state['synthesis'];
        }

        if (isset($e->payload['tokens'])) {
            $this->state['agents'][$id]['tokens'] = (int) $e->payload['tokens'];
        }

        if (isset($e->payload['event_kind'])) {
            $this->state['agents'][$id]['status'] = (string) $e->payload['event_kind'];
        }

        if ($e->kind === SwarmEventKind::BlackboardPost && ($e->payload['event_kind'] ?? null) === 'agent.complete') {
            $text = $e->payload['text'] ?? '';
            if ($text !== '') {
                $this->state['feed'][] = ['from' => $id, 'text' => $text];
                $this->state['feed'] = array_slice($this->state['feed'], -10);
            }
        }
    }
}
