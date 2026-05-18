<?php

declare(strict_types=1);

namespace Phalanx\Athena\Turn;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Activity\Suspender;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Exception\ActivityFailed;
use Phalanx\Athena\Exception\MaxInvocationsReached;
use Phalanx\Athena\Hook\StepContext;
use Phalanx\Athena\Hook\StepHook;
use Phalanx\Athena\Hook\StepHookChain;
use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Conversation\Record\ToolCall;
use Phalanx\Panoply\Conversation\Record\ToolResult;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider;
use Phalanx\Panoply\Stream;
use Phalanx\Scope\TaskScope;

final class Loop implements Activity\Executor
{
    /**
     * @param list<StepHook> $hooks
     */
    public function __construct(
        private(set) Builder $builder,
        private(set) Provider $provider,
        private(set) RuntimeFactory $runtimeFactory = new AegisRuntimeFactory(),
        private(set) array $hooks = [],
        private(set) ?Dispatcher $dispatcher = null,
        private(set) ?Suspender $suspender = null,
    ) {
    }

    public function __invoke(TaskScope $scope, Agent $agent, Activity\Config $config, ?Log $log = null): Activity\Result
    {
        $records = $log !== null ? $log->toArray() : [];
        $turn    = new Config($config->id, $config->context, $config->maxInvocations);
        $runtime = ($this->runtimeFactory)($scope);
        $chain   = new StepHookChain([...$this->hooks, ...$config->hooks]);
        $cues    = [];

        for ($i = 1; $i <= $config->maxInvocations; $i++) {
            $scope->throwIfCancelled();
            $runtime->throwIfCancelled();

            $current    = Log::from($records);
            $turnConfig = $turn->forInvocation($i);
            $invocation = $this->builder->build($scope, $agent, $current, $turnConfig);

            $hookResult = $chain->notify($scope, StepContext::beforeInvocation($turnConfig, $current, $invocation));

            if ($hookResult->outcome->terminal()) {
                return self::buildResult($config->id, $hookResult->outcome, $current, $i, $hookResult->error, $cues);
            }

            $stream = CompositeStream::wrap($scope, $this->provider->perform($invocation, $runtime));
            [$outcome, $error, $text] = $this->processCueStream(
                $scope,
                $stream,
                $chain,
                $turnConfig,
                $current,
                $invocation,
                $records,
                $cues,
                $config->id,
            );

            if ($text !== '') {
                self::pushAssistantMessage($records, $text);
            }

            $current    = Log::from($records);
            $afterCtx   = StepContext::afterInvocation($turnConfig, $current, $invocation, $outcome);
            $hookResult = $chain->notify($scope, $afterCtx);

            if ($hookResult->outcome->terminal()) {
                $outcome = $hookResult->outcome;
                $error   = $hookResult->error;
            }

            if ($outcome->terminal()) {
                return self::buildResult($config->id, $outcome, $current, $i, $error, $cues);
            }
        }

        throw new MaxInvocationsReached($config->id, $config->maxInvocations);
    }

    /**
     * @param list<Cue> $cues
     */
    private static function buildResult(
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

    /** @param list<\Phalanx\Panoply\Conversation\Record> $records */
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

    /** @param list<\Phalanx\Panoply\Conversation\Record> $records */
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

    /** @param list<\Phalanx\Panoply\Conversation\Record> $records */
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

    /**
     * @param list<Cue> $cues
     * @param list<\Phalanx\Panoply\Conversation\Record> $records
     * @return array{0: Outcome, 1: ?\Throwable, 2: string}
     */
    private function processCueStream(
        TaskScope $scope,
        CompositeStream $stream,
        StepHookChain $chain,
        Config $turnConfig,
        Log $current,
        Invocation $invocation,
        array &$records,
        array &$cues,
        string $activityId,
    ): array {
        $text    = '';
        $outcome = Outcome::Continue;
        $error   = null;

        foreach ($stream->stream() as $cue) {
            $cues[] = $cue;

            $hookResult = $chain->notify($scope, StepContext::afterCue($turnConfig, $current, $invocation, $cue));

            if ($hookResult->outcome->terminal()) {
                return [$hookResult->outcome, $hookResult->error, $text];
            }

            if ($cue instanceof TokenDelta) {
                $text .= $cue->text;
                continue;
            }

            if ($cue instanceof TokenStop) {
                $outcome = Outcome::Complete;
                continue;
            }

            if ($cue instanceof Requested) {
                [$outcome, $error] = $this->handleRequestedEffect($scope, $cue, $stream, $records, $activityId);

                if ($outcome->terminal()) {
                    return [$outcome, $error, $text];
                }
            }
        }

        return [$outcome, $error, $text];
    }

    /**
     * @param list<\Phalanx\Panoply\Conversation\Record> $records
     * @return array{0: Outcome, 1: ?\Throwable}
     */
    private function handleRequestedEffect(
        TaskScope $scope,
        Requested $cue,
        CompositeStream $stream,
        array &$records,
        string $activityId,
    ): array {
        if ($this->dispatcher === null) {
            return self::effectOutcome($cue);
        }

        $result = $this->dispatcher->dispatch($scope, $cue, $stream);

        if ($result->turnOutcome === Outcome::WaitingForApproval && $this->suspender !== null) {
            $result = ($this->suspender)(
                $scope,
                $activityId,
                Log::from($records),
                $cue,
                $this->dispatcher,
                $stream,
            );
        }

        if ($result->turnOutcome === Outcome::Continue) {
            self::pushToolCall($records, $cue);
            self::pushToolResult($records, $cue, $result->data);

            return [Outcome::Continue, null];
        }

        return [$result->turnOutcome, $result->error];
    }
}
