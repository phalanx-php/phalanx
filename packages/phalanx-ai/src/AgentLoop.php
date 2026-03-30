<?php

declare(strict_types=1);

namespace Phalanx\Ai;

use Phalanx\Ai\Event\AgentEvent;
use Phalanx\Ai\Event\TokenUsage;
use Phalanx\Ai\Message\Content;
use Phalanx\Ai\Message\Message;
use Phalanx\Ai\Provider\GenerateRequest;
use Phalanx\Ai\Provider\LlmProvider;
use Phalanx\Ai\Provider\ProviderConfig;
use Phalanx\Ai\Stream\Generation;
use Phalanx\Ai\Tool\Disposition;
use Phalanx\Ai\Tool\ToolCallBag;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Ai\Tool\ToolRegistry;
use Phalanx\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Emitter;
use Phalanx\Task\Task;

final class AgentLoop
{
    public static function run(Turn $turn, ExecutionScope $scope): Emitter
    {
        return Emitter::produce(static function (Channel $channel) use ($turn, $scope): void {
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
                $channel->emit(AgentEvent::llmStart($step, $elapsed));

                $request = new GenerateRequest(
                    conversation: $conversation,
                    tools: $schemas,
                    outputSchema: $turn->outputClass,
                );

                $generation = Generation::collect(
                    $provider->generate($request),
                    $scope,
                    static fn(AgentEvent $e) => $channel->emit($e),
                );

                $usage = $usage->add($generation->usage);

                if ($generation->toolCalls->isEmpty()) {
                    $conversation = $conversation->assistant($generation->text);
                    $result = AgentResult::fromGeneration($generation, $conversation, $step);
                    $elapsed = (hrtime(true) - $startTime) / 1e6;
                    $channel->emit(AgentEvent::complete($result, $elapsed, $usage, $step));

                    return;
                }

                $conversation = $conversation->append(
                    self::assistantWithToolUse($generation->text, $generation->toolCalls),
                );

                $toolTasks = [];
                foreach ($generation->toolCalls->all() as $toolCall) {
                    $tool = $toolRegistry->hydrate($toolCall);
                    $sfKey = $toolCall->name . ':' . hash('xxh3', json_encode($toolCall->arguments));
                    $toolTasks[] = Task::of(
                        static fn(ExecutionScope $s) => $s->singleflight(
                            $sfKey,
                            Task::of(static fn(ExecutionScope $inner) => $tool($inner)),
                        )
                    );
                }

                /** @var list<ToolOutcome> $outcomes */
                $outcomes = array_values($scope->concurrent($toolTasks));

                $terminated = false;
                foreach ($outcomes as $i => $outcome) {
                    $call = $generation->toolCalls->get($i);
                    $elapsed = (hrtime(true) - $startTime) / 1e6;

                    match ($outcome->disposition) {
                        Disposition::Terminate => (static function () use ($outcome, $conversation, $usage, $step, $channel, $elapsed, &$terminated): void {
                            $result = AgentResult::fromTool($outcome, $conversation, $usage, $step);
                            $channel->emit(AgentEvent::complete($result, $elapsed, $usage, $step));
                            $terminated = true;
                        })(),

                        Disposition::Delegate => (static function () use ($outcome, $scope, $call, &$conversation): void {
                            if ($outcome->next === null) {
                                throw new \RuntimeException('Delegate disposition requires a next task');
                            }
                            $childResult = $scope->execute($outcome->next);
                            $conversation = $conversation->appendToolResult($call->id, $childResult);
                        })(),

                        Disposition::Escalate => (static function () use ($outcome, $call, &$conversation, $channel, $elapsed, $usage, $step): void {
                            $reason = $outcome->reason ?? 'No reason provided';
                            $channel->emit(AgentEvent::escalation($reason, $elapsed, $usage, $step));
                            $conversation = $conversation->appendToolResult(
                                $call->id,
                                'Escalated to human: ' . $reason,
                            );
                        })(),

                        Disposition::Retry => (static function () use ($scope, $toolRegistry, $call, $outcome, &$conversation): void {
                            $retried = $scope->retry(
                                Task::of(static fn($s) => $toolRegistry->hydrate($call, hint: $outcome->reason)($s)),
                                $toolRegistry->retryPolicy($call),
                            );
                            $conversation = $conversation->appendToolResult($call->id, $retried);
                        })(),

                        Disposition::Continue => (static function () use ($outcome, $call, &$conversation): void {
                            $serialized = is_string($outcome->data)
                                ? $outcome->data
                                : json_encode($outcome->data, JSON_THROW_ON_ERROR);
                            $conversation = $conversation->appendToolResult($call->id, $serialized);
                        })(),
                    };

                    if ($terminated) {
                        return;
                    }
                }

                $elapsed = (hrtime(true) - $startTime) / 1e6;
                $channel->emit(AgentEvent::stepComplete($step, $elapsed, $usage));

                $stepAction = self::invokeOnStep($turn, $generation, $step, $usage, $scope);

                if ($stepAction !== null) {
                    match ($stepAction->kind) {
                        StepActionKind::Continue => null,
                        StepActionKind::Finalize => (static function () use ($stepAction, $conversation, $usage, $step, $channel, $startTime): void {
                            $text = $stepAction->finalText ?? '';
                            $conversation = $conversation->assistant($text);
                            $result = new AgentResult($text, null, $conversation, $usage, $step);
                            $elapsed = (hrtime(true) - $startTime) / 1e6;
                            $channel->emit(AgentEvent::complete($result, $elapsed, $usage, $step));
                        })(),
                        StepActionKind::Inject => (static function () use ($stepAction, &$conversation): void {
                            if ($stepAction->message !== null) {
                                $conversation = $conversation->append($stepAction->message);
                            }
                        })(),
                    };

                    if ($stepAction->kind === StepActionKind::Finalize) {
                        return;
                    }
                }
            }

            $result = AgentResult::maxStepsReached($conversation, $usage, $step);
            $elapsed = (hrtime(true) - $startTime) / 1e6;
            $channel->emit(AgentEvent::complete($result, $elapsed, $usage, $step));
        });
    }

    private static function resolveProvider(Turn $turn, ExecutionScope $scope): LlmProvider
    {
        /** @var ProviderConfig $config */
        $config = $scope->service(ProviderConfig::class);
        $preferredProvider = $turn->agent->provider();

        return $config->resolve($preferredProvider);
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
