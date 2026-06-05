<?php

declare(strict_types=1);

namespace Phalanx\Agents\Turn;

use Phalanx\Agents\Activity;
use Phalanx\Agents\Activity\Suspender;
use Phalanx\Agents\Activity\TerminalCell;
use Phalanx\Agents\Activity\TerminalState;
use Phalanx\Agents\Effect\Dispatcher;
use Phalanx\Agents\Exception\ActivityFailed;
use Phalanx\Agents\Exception\MaxInvocationsReached;
use Phalanx\Agents\Hook\StepContext;
use Phalanx\Agents\Hook\StepHook;
use Phalanx\Agents\Hook\StepHookChain;
use Phalanx\Agents\Stream\ArrayCueEmitter;
use Phalanx\Agents\Stream\ChannelCueEmitter;
use Phalanx\Agents\Stream\CueEmitter;
use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Conversation\Record\ToolCall;
use Phalanx\AiProviders\Conversation\Record\ToolResult;
use Phalanx\AiProviders\Cue;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Cue\Output\Channel;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Id;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider;
use Phalanx\AiProviders\Stream;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;

final class Loop implements Activity\Executor
{
    /**
     * @param list<StepHook> $hooks
     */
    public function __construct(
        private(set) Builder $builder,
        private(set) Provider $provider,
        private(set) RuntimeFactory $runtimeFactory = new ScopedRuntimeFactory(),
        private(set) array $hooks = [],
        private(set) ?Dispatcher $dispatcher = null,
        private(set) ?Suspender $suspender = null,
        private(set) int $channelBuffer = 32,
    ) {
    }

    public function __invoke(TaskScope $scope, Agent $agent, Activity\Config $config, ?Log $log = null): Activity\Result
    {
        if ($scope instanceof ExecutionScope) {
            return self::invokeStreaming($scope, $agent, $config, $log, $this);
        }

        return self::invokeMaterialized($scope, $agent, $config, $log, $this);
    }

    private static function invokeStreaming(
        ExecutionScope $scope,
        Agent $agent,
        Activity\Config $config,
        ?Log $log,
        self $loop,
    ): Activity\Result {
        $cueChannel = new \Phalanx\Stream\Channel($loop->channelBuffer);
        $cell = new TerminalCell();

        $records = $log !== null ? $log->toArray() : [];
        $turn = new Config($config->id, $config->context, $config->maxInvocations);
        $runtime = ($loop->runtimeFactory)($scope);
        $chain = new StepHookChain([...$loop->hooks, ...$config->hooks]);
        $emitter = new ChannelCueEmitter($cueChannel);

        $builder = $loop->builder;
        $provider = $loop->provider;
        $dispatcher = $loop->dispatcher;
        $suspender = $loop->suspender;

        $scope->go(static function (ExecutionScope $producerScope) use (
            $cueChannel,
            $cell,
            $emitter,
            $records,
            $turn,
            $runtime,
            $chain,
            $builder,
            $provider,
            $dispatcher,
            $suspender,
            $agent,
            $config,
        ): void {
            $outcome = Outcome::Continue;
            $error = null;
            $invocationCount = 0;

            try {
                for ($i = 1; $i <= $config->maxInvocations; $i++) {
                    $producerScope->throwIfCancelled();
                    $runtime->throwIfCancelled();

                    $current = Log::from($records);
                    $turnConfig = $turn->forInvocation($i);
                    $invocation = $builder->build($producerScope, $agent, $current, $turnConfig);

                    $hookResult = $chain->notify($producerScope, StepContext::beforeInvocation($turnConfig, $current, $invocation));

                    if ($hookResult->outcome->terminal()) {
                        $outcome = $hookResult->outcome;
                        $error = $hookResult->error;
                        $invocationCount = $i;
                        break;
                    }

                    $text = '';
                    $providerStream = $provider->perform($invocation, $runtime);

                    foreach ($providerStream as $cue) {
                        $emitter->emit($cue);

                        $hookResult = $chain->notify($producerScope, StepContext::afterCue($turnConfig, $current, $invocation, $cue));

                        if ($hookResult->outcome->terminal()) {
                            $outcome = $hookResult->outcome;
                            $error = $hookResult->error;
                            break 2;
                        }

                        if ($cue instanceof TokenDelta) {
                            if ($cue->channel === Channel::Message) {
                                $text .= $cue->text;
                            }

                            continue;
                        }

                        if ($cue instanceof TokenStop) {
                            $outcome = Outcome::Complete;
                            continue;
                        }

                        if ($cue instanceof Requested) {
                            [$outcome, $error] = self::handleRequestedEffect(
                                $producerScope,
                                $cue,
                                $emitter,
                                $records,
                                $config->id,
                                $dispatcher,
                                $suspender,
                            );

                            if ($outcome->terminal()) {
                                break 2;
                            }
                        }
                    }

                    if ($text !== '') {
                        self::pushAssistantMessage($records, $text);
                    }

                    $invocationCount = $i;

                    $current = Log::from($records);
                    $afterCtx = StepContext::afterInvocation($turnConfig, $current, $invocation, $outcome);
                    $hookResult = $chain->notify($producerScope, $afterCtx);

                    if ($hookResult->outcome->terminal()) {
                        $outcome = $hookResult->outcome;
                        $error = $hookResult->error;
                    }

                    if ($outcome->terminal()) {
                        break;
                    }
                }

                if ($invocationCount === 0) {
                    $invocationCount = 1;
                }

                if (!$outcome->terminal()) {
                    $outcome = Outcome::MaxInvocationsReached;
                }

                $cell->resolve(new TerminalState(
                    state: self::stateFor($outcome),
                    outcome: $outcome,
                    log: Log::from($records),
                    invocations: $invocationCount,
                    error: $error,
                ));
            } catch (Cancelled $e) {
                $cueChannel->error($e);
                $cell->resolve(new TerminalState(
                    state: Activity\State::Cancelled,
                    outcome: Outcome::Cancelled,
                    log: Log::from($records),
                    invocations: max($invocationCount, 1),
                    error: $e,
                ));
            } catch (\Throwable $e) {
                $cueChannel->error($e);
                $cell->resolve(new TerminalState(
                    state: Activity\State::Failed,
                    outcome: Outcome::Failed,
                    log: Log::from($records),
                    invocations: max($invocationCount, 1),
                    error: $e,
                ));
            } finally {
                if ($cueChannel->isOpen) {
                    $cueChannel->complete();
                }
            }
        }, 'agent.loop.' . $config->id);

        return Activity\Result::lazy(
            activityId: $config->id,
            stream: Stream::from($cueChannel->consume()),
            cell: $cell,
        );
    }

    private static function invokeMaterialized(
        TaskScope $scope,
        Agent $agent,
        Activity\Config $config,
        ?Log $log,
        self $loop,
    ): Activity\Result {
        $records = $log !== null ? $log->toArray() : [];
        $turn = new Config($config->id, $config->context, $config->maxInvocations);
        $runtime = ($loop->runtimeFactory)($scope);
        $chain = new StepHookChain([...$loop->hooks, ...$config->hooks]);
        $cues = [];

        for ($i = 1; $i <= $config->maxInvocations; $i++) {
            $scope->throwIfCancelled();
            $runtime->throwIfCancelled();

            $current = Log::from($records);
            $turnConfig = $turn->forInvocation($i);
            $invocation = $loop->builder->build($scope, $agent, $current, $turnConfig);

            $hookResult = $chain->notify($scope, StepContext::beforeInvocation($turnConfig, $current, $invocation));

            if ($hookResult->outcome->terminal()) {
                return self::buildEagerResult($config->id, $hookResult->outcome, $current, $i, $hookResult->error, $cues);
            }

            [$outcome, $error, $text] = self::processCueStreamMaterialized(
                $scope,
                $loop->provider->perform($invocation, $runtime),
                $chain,
                $turnConfig,
                $current,
                $invocation,
                $records,
                $cues,
                $config->id,
                $loop->dispatcher,
                $loop->suspender,
            );

            if ($text !== '') {
                self::pushAssistantMessage($records, $text);
            }

            $current = Log::from($records);
            $afterCtx = StepContext::afterInvocation($turnConfig, $current, $invocation, $outcome);
            $hookResult = $chain->notify($scope, $afterCtx);

            if ($hookResult->outcome->terminal()) {
                $outcome = $hookResult->outcome;
                $error = $hookResult->error;
            }

            if ($outcome->terminal()) {
                return self::buildEagerResult($config->id, $outcome, $current, $i, $error, $cues);
            }
        }

        throw new MaxInvocationsReached($config->id, $config->maxInvocations);
    }

    /**
     * @param iterable<Cue> $providerCues
     * @param list<Cue> $cues
     * @param list<\Phalanx\AiProviders\Conversation\Record> $records
     * @return array{0: Outcome, 1: ?\Throwable, 2: string}
     */
    private static function processCueStreamMaterialized(
        TaskScope $scope,
        iterable $providerCues,
        StepHookChain $chain,
        Config $turnConfig,
        Log $current,
        Invocation $invocation,
        array &$records,
        array &$cues,
        string $activityId,
        ?Dispatcher $dispatcher,
        ?Suspender $suspender,
    ): array {
        $text = '';
        $outcome = Outcome::Continue;
        $error = null;
        $emitter = new ArrayCueEmitter();

        foreach ($providerCues as $cue) {
            $scope->throwIfCancelled();
            $cues[] = $cue;

            $hookResult = $chain->notify($scope, StepContext::afterCue($turnConfig, $current, $invocation, $cue));

            if ($hookResult->outcome->terminal()) {
                return [$hookResult->outcome, $hookResult->error, $text];
            }

            if ($cue instanceof TokenDelta) {
                if ($cue->channel === Channel::Message) {
                    $text .= $cue->text;
                }

                continue;
            }

            if ($cue instanceof TokenStop) {
                $outcome = Outcome::Complete;
                continue;
            }

            if ($cue instanceof Requested) {
                [$outcome, $error] = self::handleRequestedEffect(
                    $scope,
                    $cue,
                    $emitter,
                    $records,
                    $activityId,
                    $dispatcher,
                    $suspender,
                );

                array_push($cues, ...$emitter->drain());

                if ($outcome->terminal()) {
                    return [$outcome, $error, $text];
                }
            }
        }

        return [$outcome, $error, $text];
    }

    /**
     * @param list<\Phalanx\AiProviders\Conversation\Record> $records
     * @return array{0: Outcome, 1: ?\Throwable}
     */
    private static function handleRequestedEffect(
        TaskScope $scope,
        Requested $cue,
        CueEmitter $emitter,
        array &$records,
        string $activityId,
        ?Dispatcher $dispatcher,
        ?Suspender $suspender,
    ): array {
        if ($dispatcher === null) {
            return self::effectOutcome($cue);
        }

        $result = $dispatcher->dispatch($scope, $cue, $emitter);

        if ($result->turnOutcome === Outcome::WaitingForApproval && $suspender !== null) {
            $result = ($suspender)(
                $scope,
                $activityId,
                Log::from($records),
                $cue,
                $dispatcher,
                $emitter,
            );
        }

        if ($result->turnOutcome === Outcome::Continue) {
            self::pushToolCall($records, $cue);
            self::pushToolResult($records, $cue, $result->data);

            return [Outcome::Continue, null];
        }

        return [$result->turnOutcome, $result->error];
    }

    /**
     * @param list<Cue> $cues
     */
    private static function buildEagerResult(
        string $activityId,
        Outcome $outcome,
        Log $log,
        int $invocations,
        ?\Throwable $error,
        array $cues,
    ): Activity\Result {
        return new Activity\Result(
            activityId: $activityId,
            state: self::stateFor($outcome),
            outcome: $outcome,
            log: $log,
            invocations: $invocations,
            error: $error,
            stream: Stream::from($cues),
        );
    }

    /** @param list<\Phalanx\AiProviders\Conversation\Record> $records */
    private static function pushAssistantMessage(array &$records, string $text): void
    {
        $records[] = new Message(
            id: 'msg_' . Id::generate(),
            sequence: count($records) + 1,
            at: new \DateTimeImmutable(),
            role: 'assistant',
            text: $text,
        );
    }

    /**
     * @return array{0: Outcome, 1: ?\Throwable}
     */
    private static function effectOutcome(Requested $cue): array
    {
        if ($cue->requiresApproval) {
            return [Outcome::WaitingForApproval, null];
        }

        return [
            Outcome::Failed,
            new ActivityFailed(sprintf('No effect dispatcher is available for %s.', $cue->kind->value)),
        ];
    }

    /** @param list<\Phalanx\AiProviders\Conversation\Record> $records */
    private static function pushToolCall(array &$records, Requested $request): void
    {
        $records[] = new ToolCall(
            id: 'rec_' . Id::generate(),
            sequence: count($records) + 1,
            at: new \DateTimeImmutable(),
            callId: $request->effectId,
            toolName: $request->effectId,
            arguments: $request->arguments,
        );
    }

    /** @param list<\Phalanx\AiProviders\Conversation\Record> $records */
    private static function pushToolResult(array &$records, Requested $request, mixed $data): void
    {
        $records[] = new ToolResult(
            id: 'rec_' . Id::generate(),
            sequence: count($records) + 1,
            at: new \DateTimeImmutable(),
            callId: $request->effectId,
            output: is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private static function stateFor(Outcome $outcome): Activity\State
    {
        return match ($outcome) {
            Outcome::Complete => Activity\State::Completed,
            Outcome::WaitingForApproval => Activity\State::Suspended,
            Outcome::Cancelled => Activity\State::Cancelled,
            default => Activity\State::Failed,
        };
    }
}
