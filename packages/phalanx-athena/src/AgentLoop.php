<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Closure;
use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Message\Content;
use Phalanx\Athena\Message\Message;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Athena\Provider\LlmProvider;
use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Athena\Stream\Generation;
use Phalanx\Athena\Tool\Disposition;
use Phalanx\Athena\Tool\ToolCall;
use Phalanx\Athena\Tool\ToolCallBag;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use Phalanx\Task\Task;

final class AgentLoop
{
    public static function run(Turn $turn, ExecutionScope $scope, ?string $agentName = null): Emitter
    {
        return Emitter::produce(static function (Channel $channel) use ($turn, $scope, $agentName): void {
            // Internal relay to tag events with the agent name
            $relay = static function (AgentEvent $e) use ($channel, $agentName): void {
                $channel->emit($agentName !== null ? $e->withAgent($agentName) : $e);
            };

            $conversation = $turn->buildConversation();
            $provider = self::resolveProvider($turn, $scope);
            $toolRegistry = ToolRegistry::from($turn->agent->tools());
            $schemas = $toolRegistry->allSchemas();
            $startTime = hrtime(true);
            $usage = TokenUsage::zero();
            $step = 0;

            while ($step < $turn->maxSteps) {
                $step++;
                $elapsed = (hrtime(true) - $startTime) / 1e6;
                $relay(AgentEvent::llmStart($step, $elapsed));

                $request = new GenerateRequest(
                    conversation: $conversation,
                    tools: $schemas,
                    outputSchema: $turn->outputClass,
                    model: self::resolveModel($turn),
                );

                $generation = Generation::collect(
                    $provider->generate($request),
                    $scope,
                    $relay,
                );

                $usage = $usage->add($generation->usage);

                if ($generation->toolCalls->isEmpty()) {
                    $conversation = $conversation->assistant($generation->text);
                    $result = AgentResult::fromGeneration($generation, $conversation, $step);
                    $elapsed = (hrtime(true) - $startTime) / 1e6;
                    $relay(AgentEvent::complete($result, $elapsed, $usage, $step));

                    return;
                }

                $conversation = $conversation->append(
                    self::assistantWithToolUse($generation->text, $generation->toolCalls),
                );

                $toolTasks = [];
                foreach ($generation->toolCalls->all() as $toolCall) {
                    $tool = $toolRegistry->hydrate($toolCall);
                    $serializedArguments = json_encode($toolCall->arguments, JSON_THROW_ON_ERROR);
                    $sfKey = $toolCall->name . ':' . hash('xxh3', $serializedArguments);
                    $toolTasks[] = Task::of(
                        static fn(ExecutionScope $s) => $s->singleflight(
                            $sfKey,
                            Task::of(static fn(ExecutionScope $inner) => $tool($inner)),
                        )
                    );
                }

                /** @var list<ToolOutcome> $outcomes */
                $outcomes = array_values($scope->concurrent(...$toolTasks));

                foreach ($outcomes as $i => $outcome) {
                    $call = $generation->toolCalls->get($i);
                    $elapsed = (hrtime(true) - $startTime) / 1e6;

                    if (self::applyToolOutcome(
                        $outcome,
                        $call,
                        $conversation,
                        $toolRegistry,
                        $scope,
                        $usage,
                        $step,
                        $relay,
                        $elapsed,
                    )) {
                        return;
                    }
                }

                $elapsed = (hrtime(true) - $startTime) / 1e6;
                $relay(AgentEvent::stepComplete($step, $elapsed, $usage));

                $stepAction = self::invokeOnStep($turn, $generation, $step, $usage, $scope);

                if ($stepAction !== null) {
                    if (self::applyStepAction($stepAction, $conversation, $usage, $step, $relay, $startTime)) {
                        return;
                    }
                }
            }

            $result = AgentResult::maxStepsReached($conversation, $usage, $step);
            $elapsed = (hrtime(true) - $startTime) / 1e6;
            $relay(AgentEvent::complete($result, $elapsed, $usage, $step));
        });
    }

    private static function resolveProvider(Turn $turn, Scope $scope): LlmProvider
    {
        /** @var ProviderConfig $config */
        $config = $scope->service(ProviderConfig::class);
        $preferredProvider = $turn->agent->provider();

        return $config->resolve($preferredProvider);
    }

    private static function resolveModel(Turn $turn): ?string
    {
        if (!method_exists($turn->agent, 'model')) {
            return null;
        }

        $model = $turn->agent->model();
        return is_string($model) && $model !== '' ? $model : null;
    }

    private static function invokeOnStep(
        Turn $turn,
        Generation $generation,
        int $step,
        TokenUsage $usage,
        ExecutionScope $scope,
    ): ?StepAction {
        if ($turn->onStepHook === null) {
            return null;
        }

        $stepResult = new StepResult(
            number: $step,
            text: $generation->text,
            toolCalls: $generation->toolCalls,
            usage: $usage,
        );

        return ($turn->onStepHook)($stepResult, $scope);
    }

    /** @param Closure(AgentEvent): void $relay */
    private static function applyToolOutcome(
        ToolOutcome $outcome,
        ToolCall $call,
        Conversation &$conversation,
        ToolRegistry $toolRegistry,
        ExecutionScope $scope,
        TokenUsage $usage,
        int $step,
        Closure $relay,
        float $elapsed,
    ): bool {
        match ($outcome->disposition) {
            Disposition::Terminate => self::completeFromTool($outcome, $conversation, $usage, $step, $relay, $elapsed),
            Disposition::Delegate => self::appendDelegatedToolResult($outcome, $call, $conversation, $scope),
            Disposition::Escalate => self::appendEscalationToolResult(
                $outcome,
                $call,
                $conversation,
                $usage,
                $step,
                $relay,
                $elapsed,
            ),
            Disposition::Retry => self::appendRetriedToolResult($outcome, $call, $conversation, $toolRegistry, $scope),
            Disposition::Continue => self::appendContinuedToolResult($outcome, $call, $conversation),
        };

        return $outcome->disposition === Disposition::Terminate;
    }

    /** @param Closure(AgentEvent): void $relay */
    private static function completeFromTool(
        ToolOutcome $outcome,
        Conversation $conversation,
        TokenUsage $usage,
        int $step,
        Closure $relay,
        float $elapsed,
    ): void {
        $result = AgentResult::fromTool($outcome, $conversation, $usage, $step);
        $relay(AgentEvent::complete($result, $elapsed, $usage, $step));
    }

    private static function appendDelegatedToolResult(
        ToolOutcome $outcome,
        ToolCall $call,
        Conversation &$conversation,
        ExecutionScope $scope,
    ): void {
        if ($outcome->next === null) {
            throw new \RuntimeException('Delegate disposition requires a next task');
        }

        $childResult = $scope->execute($outcome->next);
        $conversation = $conversation->appendToolResult($call->id, $childResult);
    }

    /** @param Closure(AgentEvent): void $relay */
    private static function appendEscalationToolResult(
        ToolOutcome $outcome,
        ToolCall $call,
        Conversation &$conversation,
        TokenUsage $usage,
        int $step,
        Closure $relay,
        float $elapsed,
    ): void {
        $reason = $outcome->reason ?? 'No reason provided';
        $relay(AgentEvent::escalation($reason, $elapsed, $usage, $step));
        $conversation = $conversation->appendToolResult($call->id, 'Escalated to human: ' . $reason);
    }

    private static function appendRetriedToolResult(
        ToolOutcome $outcome,
        ToolCall $call,
        Conversation &$conversation,
        ToolRegistry $toolRegistry,
        ExecutionScope $scope,
    ): void {
        $retried = $scope->retry(
            Task::of(static fn(ExecutionScope $s) => $toolRegistry->hydrate($call, hint: $outcome->reason)($s)),
            $toolRegistry->retryPolicy($call),
        );

        $conversation = $conversation->appendToolResult($call->id, $retried);
    }

    private static function appendContinuedToolResult(
        ToolOutcome $outcome,
        ToolCall $call,
        Conversation &$conversation,
    ): void {
        $serialized = is_string($outcome->data)
            ? $outcome->data
            : json_encode($outcome->data, JSON_THROW_ON_ERROR);

        $conversation = $conversation->appendToolResult($call->id, $serialized);
    }

    /** @param Closure(AgentEvent): void $relay */
    private static function applyStepAction(
        StepAction $stepAction,
        Conversation &$conversation,
        TokenUsage $usage,
        int $step,
        Closure $relay,
        int $startTime,
    ): bool {
        if ($stepAction->kind === StepActionKind::Continue) {
            return false;
        }

        if ($stepAction->kind === StepActionKind::Inject) {
            if ($stepAction->message !== null) {
                $conversation = $conversation->append($stepAction->message);
            }

            return false;
        }

        $text = $stepAction->finalText ?? '';
        $conversation = $conversation->assistant($text);
        $result = new AgentResult($text, null, $conversation, $usage, $step);
        $elapsed = (hrtime(true) - $startTime) / 1e6;
        $relay(AgentEvent::complete($result, $elapsed, $usage, $step));

        return true;
    }

    private static function assistantWithToolUse(string $text, ToolCallBag $toolCalls): Message
    {
        $contentBlocks = [];

        if ($text !== '') {
            $contentBlocks[] = Content::text($text);
        }

        foreach ($toolCalls->all() as $call) {
            $contentBlocks[] = Content::toolCall($call->id, $call->name, $call->arguments);
        }

        return Message::assistant($contentBlocks);
    }
}
