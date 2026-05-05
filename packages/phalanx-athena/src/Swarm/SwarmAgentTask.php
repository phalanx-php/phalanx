<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\StepAction;
use Phalanx\Athena\StepResult;
use Phalanx\Athena\Turn;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;
use RuntimeException;

class SwarmAgentTask implements Executable
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
        $this->emit($scope, SwarmEventKind::Online);

        $turn = Turn::begin($this->agent)->stream();

        $prompt = 'IMPORTANT: Speak in 1-2 sentences. Only contribute what your specialty uniquely adds. '
            . 'Do not repeat. End with [PROPOSAL], [QUESTION], or [BLOCKED].';

        $turn = $turn->message($prompt);

        $bus = $this->bus;
        $agentId = $this->agentId;
        $config = $this->config;
        $turn = $turn->onStep(static function (
            StepResult $step,
            ExecutionScope $s,
        ) use (
            $bus,
            $agentId,
            $config,
        ): StepAction {
            if (self::shouldRequestClearance($step->text)) {
                self::requestClearanceFor(
                    $s,
                    $bus,
                    $agentId,
                    $config,
                    'Task: ' . substr($step->text, 0, 100),
                );
            }
            return StepAction::continue();
        });

        $events = AgentLoop::run($turn, $scope, $this->agentId);

        foreach ($events($scope) as $event) {
            $this->emit($scope, SwarmEventKind::BlackboardPost, self::eventPayload($event), inner: $event);
        }

        return null;
    }

    public static function shouldRequestClearance(string $text): bool
    {
        $lower = strtolower($text);
        return str_contains($lower, 'i will') || str_contains($lower, 'executing') || str_contains($lower, 'starting');
    }

    /**
     * @param array<string, mixed> $payload
     * @param string|list<string>|null $addressedTo
     */
    public function emit(
        Scope&Suspendable $scope,
        SwarmEventKind $kind,
        array $payload = [],
        ?string $traceId = null,
        ?AgentEvent $inner = null,
        string|array|null $addressedTo = null,
    ): void {
        $this->bus->emit($scope, new SwarmEvent(
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

    private static function requestClearanceFor(
        ExecutionScope $scope,
        SwarmBus $bus,
        string $agentId,
        SwarmConfig $config,
        string $description,
    ): void {
        $traceId = uniqid('clr_');
        $events = $bus->subscribe([
            'workspace' => $config->workspace,
            'trace_id' => $traceId,
            'addressed_to' => $agentId,
        ]);

        $bus->emit($scope, new SwarmEvent(
            from: $agentId,
            kind: SwarmEventKind::ClearanceRequested,
            workspace: $config->workspace,
            session: $config->session,
            payload: ['description' => $description],
            addressedTo: 'ADMIN',
            traceId: $traceId,
        ));

        $scope->timeout(
            60.0,
            Task::of(static function (ExecutionScope $s) use ($events): void {
                foreach ($events($s) as $event) {
                    if ($event->kind === SwarmEventKind::ClearanceGranted) {
                        return;
                    }
                    if ($event->kind === SwarmEventKind::ClearanceDenied) {
                        throw new RuntimeException('Clearance denied: ' . ($event->payload['reason'] ?? 'No reason'));
                    }
                }
            }),
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
