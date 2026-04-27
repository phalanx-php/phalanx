<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Turn;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Athena\Event\AgentEventKind;

final class ChatAdminTask implements Executable
{
    /** @var array<string, SwarmEvent> */
    private array $clearanceQueue = [];

    public function __construct(
        public readonly string $agentId,
        public readonly AgentDefinition $agent,
        public readonly SwarmBus $bus,
        public readonly SwarmConfig $config,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $this->emit(SwarmEventKind::Online);

        $bus = $this->bus;
        $workspace = $this->config->workspace;
        $self = $this;

        return $scope->concurrent([
            'monitor' => \Phalanx\Task\Task::of(static function (ExecutionScope $s) use ($bus, $workspace, $self) {
                foreach ($bus->subscribe([
                    'workspace' => $workspace,
                    'kinds' => [
                        SwarmEventKind::ClearanceRequested,
                        SwarmEventKind::BlackboardPost,
                        SwarmEventKind::PlanningProposal,
                        SwarmEventKind::PlanningQuestion,
                        SwarmEventKind::PlanningBlocked,
                        SwarmEventKind::FinalPlanRequested,
                    ],
                ])($s) as $event) {
                    if ($event->kind === SwarmEventKind::ClearanceRequested) {
                        $self->handleClearance($event);
                    }
                    
                    $self->synthesize($s);
                }
            })
        ]);
    }

    public function handleClearance(SwarmEvent $req): void
    {
        $this->clearanceQueue[$req->traceId] = $req;
        $this->autoProcessClearance($req);
    }

    private function autoProcessClearance(SwarmEvent $req): void
    {
        $this->emit(
            kind: SwarmEventKind::ClearanceGranted,
            addressedTo: $req->from,
            traceId: $req->traceId
        );
        unset($this->clearanceQueue[$req->traceId]);
    }

    public function synthesize(ExecutionScope $scope): void
    {
        $this->emit(
            kind: SwarmEventKind::SummaryUpdate,
            payload: ['text' => "Monitoring swarm state. Active queue: " . count($this->clearanceQueue)]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param string|list<string>|null $addressedTo
     */
    private function emit(SwarmEventKind $kind, array $payload = [], ?string $traceId = null, string|array|null $addressedTo = null): void
    {
        $this->bus->emit(new SwarmEvent(
            from: $this->agentId,
            kind: $kind,
            workspace: $this->config->workspace,
            session: $this->config->session,
            payload: $payload,
            addressedTo: $addressedTo,
            traceId: $traceId
        ));
    }
}
