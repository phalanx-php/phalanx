<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;

final class ChatAdminTask implements Executable
{
    public function __construct(
        public readonly string $agentId,
        public readonly AgentDefinition $agent,
        public readonly SwarmBus $bus,
        public readonly SwarmConfig $config,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        self::emitFor($scope, $this->bus, $this->agentId, $this->config, SwarmEventKind::Online);

        /** @var array<string, SwarmEvent> $clearanceQueue */
        $clearanceQueue = [];

        $bus = $this->bus;
        $agentId = $this->agentId;
        $config = $this->config;
        $events = $bus->subscribe([
            'workspace' => $config->workspace,
            'kinds' => [
                SwarmEventKind::ClearanceRequested,
                SwarmEventKind::BlackboardPost,
                SwarmEventKind::PlanningProposal,
                SwarmEventKind::PlanningQuestion,
                SwarmEventKind::PlanningBlocked,
                SwarmEventKind::FinalPlanRequested,
            ],
        ]);

        return $scope->concurrent(
            monitor: Task::of(static function (
                ExecutionScope $s,
            ) use (
                $bus,
                $events,
                $agentId,
                $config,
                &$clearanceQueue
            ): void {
                foreach ($events($s) as $event) {
                    if ($event->kind === SwarmEventKind::ClearanceRequested) {
                        self::handleClearance($s, $bus, $agentId, $config, $event, $clearanceQueue);
                    }

                    self::synthesize($s, $bus, $agentId, $config, $clearanceQueue);
                }
            }),
        );
    }

    /** @param array<string, SwarmEvent> $clearanceQueue */
    private static function handleClearance(
        Scope&Suspendable $scope,
        SwarmBus $bus,
        string $agentId,
        SwarmConfig $config,
        SwarmEvent $req,
        array &$clearanceQueue,
    ): void {
        $clearanceQueue[$req->traceId] = $req;
        self::emitFor(
            $scope,
            $bus,
            $agentId,
            $config,
            SwarmEventKind::ClearanceGranted,
            traceId: $req->traceId,
            addressedTo: $req->from,
        );
        unset($clearanceQueue[$req->traceId]);
    }

    /** @param array<string, SwarmEvent> $clearanceQueue */
    private static function synthesize(
        Scope&Suspendable $scope,
        SwarmBus $bus,
        string $agentId,
        SwarmConfig $config,
        array $clearanceQueue,
    ): void {
        self::emitFor(
            $scope,
            $bus,
            $agentId,
            $config,
            SwarmEventKind::SummaryUpdate,
            payload: ['text' => 'Monitoring swarm state. Active queue: ' . count($clearanceQueue)],
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param string|list<string>|null $addressedTo
     */
    private static function emitFor(
        Scope&Suspendable $scope,
        SwarmBus $bus,
        string $agentId,
        SwarmConfig $config,
        SwarmEventKind $kind,
        array $payload = [],
        ?string $traceId = null,
        string|array|null $addressedTo = null,
    ): void {
        $bus->emit($scope, new SwarmEvent(
            from: $agentId,
            kind: $kind,
            workspace: $config->workspace,
            session: $config->session,
            payload: $payload,
            addressedTo: $addressedTo,
            traceId: $traceId,
        ));
    }
}
