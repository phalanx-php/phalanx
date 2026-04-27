<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Turn;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\StepAction;
use Phalanx\Task\Task;
use React\Promise\Deferred;

class SwarmAgentTask implements Executable
{
    public function __construct(
        public readonly string $agentId,
        public readonly AgentDefinition $agent,
        public readonly SwarmBus $bus,
        public readonly SwarmConfig $config,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $this->emit(SwarmEventKind::Online);

        $turn = Turn::begin($this->agent)->stream();
        
        $turn = $turn->message("IMPORTANT: Speak in 1-2 sentences. Only contribute what your specialty uniquely adds. Do not repeat. End with [PROPOSAL], [QUESTION], or [BLOCKED].");

        $self = $this;
        $turn = $turn->onStep(static function ($step, $s) use ($self) {
            if ($self->shouldRequestClearance($step->text)) {
                 $self->requestClearance($s, "Task: " . substr($step->text, 0, 100));
            }
            return StepAction::continue();
        });

        $events = AgentLoop::run($turn, $scope, $this->agentId);

        foreach ($events($scope) as $event) {
            $this->emit(SwarmEventKind::BlackboardPost, self::eventPayload($event), inner: $event);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string|list<string>|null $addressedTo
     */
    public function emit(SwarmEventKind $kind, array $payload = [], ?string $traceId = null, ?AgentEvent $inner = null, string|array|null $addressedTo = null): void
    {
        $this->bus->emit(new SwarmEvent(
            from: $this->agentId,
            kind: $kind,
            workspace: $this->config->workspace,
            session: $this->config->session,
            payload: $payload,
            addressedTo: $addressedTo,
            traceId: $traceId,
            inner: $inner
        ));
    }

    public function shouldRequestClearance(string $text): bool
    {
        $lower = strtolower($text);
        return str_contains($lower, 'i will') || str_contains($lower, 'executing') || str_contains($lower, 'starting');
    }

    public function requestClearance(ExecutionScope $scope, string $description): void
    {
        $traceId = uniqid('clr_');
        $agentId = $this->agentId;
        $workspace = $this->config->workspace;
        $events = $this->bus->subscribe([
            'workspace' => $workspace,
            'trace_id' => $traceId,
            'addressed_to' => $agentId,
        ]);

        $deferred = new Deferred();

        $this->emit(
            kind: SwarmEventKind::ClearanceRequested,
            payload: ['description' => $description],
            traceId: $traceId,
            addressedTo: 'ADMIN'
        );

        $scope->defer(
            Task::of(static function (ExecutionScope $s) use ($events, $deferred): void {
                foreach ($events($s) as $event) {
                    if ($event->kind === SwarmEventKind::ClearanceGranted) {
                        $deferred->resolve(true);
                        return;
                    }
                    if ($event->kind === SwarmEventKind::ClearanceDenied) {
                        $deferred->reject(new \RuntimeException("Clearance denied: " . ($event->payload['reason'] ?? 'No reason')));
                        return;
                    }
                }
            })
        );

        $scope->timeout(
            60.0,
            Task::of(static fn(ExecutionScope $s): mixed => $s->await($deferred->promise())),
        );
    }

    /** @return array<string, mixed> */
    private static function eventPayload(AgentEvent $event): array
    {
        $payload = [
            'event_kind' => $event->kind->value,
            'tokens' => $event->usageSoFar->total,
        ];

        if ($event->kind === AgentEventKind::TokenDelta) {
            $payload['text'] = $event->data->text ?? '';
        }

        if ($event->kind === AgentEventKind::AgentComplete) {
            $payload['text'] = $event->data->text ?? '';
        }

        return $payload;
    }
}
