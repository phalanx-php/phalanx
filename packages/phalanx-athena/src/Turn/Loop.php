<?php

declare(strict_types=1);

namespace Phalanx\Athena\Turn;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Exception\ActivityFailed;
use Phalanx\Athena\Exception\MaxInvocationsReached;
use Phalanx\Athena\Hook\StepContext;
use Phalanx\Athena\Hook\StepHook;
use Phalanx\Athena\Hook\StepHookResult;
use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Conversation\Record\ToolCall;
use Phalanx\Panoply\Conversation\Record\ToolResult;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Id;
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
    ) {
    }

    public function __invoke(TaskScope $scope, Agent $agent, Activity\Config $config, ?Log $log = null): Activity\Result
    {
        $records = $log !== null ? $log->toArray() : [];
        $turn    = new Config($config->id, $config->context, $config->maxInvocations);
        $runtime = ($this->runtimeFactory)($scope);
        $hooks   = [...$this->hooks, ...$config->hooks];
        $cues    = [];

        for ($i = 1; $i <= $config->maxInvocations; $i++) {
            $scope->throwIfCancelled();
            $runtime->throwIfCancelled();

            $current    = Log::from($records);
            $turnConfig = $turn->forInvocation($i);
            $invocation = $this->builder->build($scope, $agent, $current, $turnConfig);
            $context    = StepContext::beforeInvocation($turnConfig, $current, $invocation);
            $hookResult = self::notify($scope, $context, $hooks);

            if ($hookResult->outcome !== Outcome::Continue) {
                return new Activity\Result(
                    $config->id,
                    self::stateFor($hookResult->outcome),
                    $hookResult->outcome,
                    $current,
                    $i,
                    $hookResult->error,
                    stream: Stream::from($cues),
                );
            }

            $text    = '';
            $outcome = Outcome::Continue;
            $error   = null;
            $stream  = CompositeStream::wrap($scope, $this->provider->perform($invocation, $runtime));

            foreach ($stream->stream() as $cue) {
                $cues[] = $cue;
                $hookResult = self::notify(
                    $scope,
                    StepContext::afterCue($turnConfig, $current, $invocation, $cue),
                    $hooks,
                );
                if ($hookResult->outcome !== Outcome::Continue) {
                    $outcome = $hookResult->outcome;
                    $error   = $hookResult->error;
                    break;
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
                    if ($this->dispatcher === null) {
                        [$outcome, $error] = self::effectOutcome($cue);
                        break;
                    }

                    $result = $this->dispatcher->dispatch($scope, $cue, $stream);

                    if ($result->turnOutcome === Outcome::Continue) {
                        self::pushToolCall($records, $cue);
                        self::pushToolResult($records, $cue, $result->data);
                        continue;
                    }

                    $outcome = $result->turnOutcome;
                    $error   = $result->error;
                    break;
                }
            }

            if ($text !== '') {
                self::pushAssistantMessage($records, $text);
            }

            $current = Log::from($records);

            $hookResult = self::notify(
                $scope,
                StepContext::afterInvocation($turnConfig, $current, $invocation, $outcome),
                $hooks,
            );
            if ($hookResult->outcome !== Outcome::Continue) {
                $outcome = $hookResult->outcome;
                $error   = $hookResult->error;
            }

            if ($outcome->terminal()) {
                return new Activity\Result(
                    activityId: $config->id,
                    state: self::stateFor($outcome),
                    outcome: $outcome,
                    log: $current,
                    invocations: $i,
                    error: $error,
                    stream: Stream::from($cues),
                );
            }
        }

        throw new MaxInvocationsReached($config->id, $config->maxInvocations);
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
     * @param list<StepHook> $hooks
     */
    private static function notify(
        TaskScope $scope,
        StepContext $context,
        array $hooks,
    ): StepHookResult {
        if ($hooks === []) {
            return StepHookResult::continue();
        }

        foreach ($hooks as $hook) {
            $result = $hook($scope, $context);
            if ($result->outcome !== Outcome::Continue) {
                return $result;
            }
        }

        return StepHookResult::continue();
    }
}
