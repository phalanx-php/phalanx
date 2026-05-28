<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

use BgAgents\Daemon8\BgEvent;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\AgentResult;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;
use Phalanx\Athena\Turn;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Single stateless specialist invocation.
 *
 * 1. Build ContextPack (identity + recent obs + RAG + situational).
 * 2. Run AgentLoop with a SpecialistAgent built from the pack.
 * 3. Emit the response back to daemon8 as a SwarmEvent (BlackboardPost).
 * 4. Return SpecialistResponse with usage + latency.
 *
 * The kind ('agent.intent_proposal' for multi-specialist work, or
 * 'agent.final_answer' for solo) is decided by the caller via $eventKind.
 */
final readonly class InvokeSpecialist implements Executable
{
    public function __construct(
        public Specialist $specialist,
        public string $prompt,
        public string $eventKind = BgEvent::AGENT_FINAL_ANSWER,
        public ?string $traceId = null,
        public ?string $causationId = null,
    ) {}

    public function __invoke(ExecutionScope $scope): SpecialistResponse
    {
        $start = hrtime(true);

        $pack = $scope->service(ContextPackBuilder::class)
            ->build($scope, $this->specialist, $this->prompt);

        $agent = new SpecialistAgent(
            systemPrompt: $pack->renderSystemPrompt(),
            providerName: $this->specialist->provider,
            modelName: $this->specialist->model,
        );

        $turn = Turn::begin($agent)->message($pack->situational);
        $events = AgentLoop::run($turn, $scope, agentName: $this->specialist->name);
        $result = AgentResult::awaitFrom($events, $scope);

        $latencyMs = (hrtime(true) - $start) / 1e6;

        $response = new SpecialistResponse(
            from: $this->specialist->name,
            model: $this->specialist->model,
            provider: $this->specialist->provider,
            text: $result->text,
            tokensIn: $result->usage->input,
            tokensOut: $result->usage->output,
            steps: $result->steps,
            latencyMs: $latencyMs,
        );

        self::emitToBlackboard($scope, $this->specialist, $response, $this->eventKind, $this->prompt, $this->traceId, $this->causationId);

        return $response;
    }

    private static function emitToBlackboard(
        ExecutionScope $scope,
        Specialist $specialist,
        SpecialistResponse $response,
        string $eventKind,
        string $prompt,
        ?string $traceId,
        ?string $causationId,
    ): void {
        /** @var SwarmBus $bus */
        $bus = $scope->service(SwarmBus::class);
        /** @var SwarmConfig $config */
        $config = $scope->service(SwarmConfig::class);

        $bus->emit(new SwarmEvent(
            from: $specialist->name,
            kind: SwarmEventKind::BlackboardPost,
            workspace: $config->workspace,
            session: $config->session,
            payload: [
                'bg_kind' => $eventKind,
                'specialist' => $specialist->name,
                'model' => $response->model,
                'provider' => $response->provider,
                'prompt' => $prompt,
                'response' => $response->text,
                'tokens' => [
                    'in' => $response->tokensIn,
                    'out' => $response->tokensOut,
                ],
                'steps' => $response->steps,
                'latency_ms' => $response->latencyMs,
            ],
            traceId: $traceId,
            causationId: $causationId,
        ));
    }
}
