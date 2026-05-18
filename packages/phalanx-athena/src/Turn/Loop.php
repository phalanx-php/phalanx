<?php

declare(strict_types=1);

namespace Phalanx\Athena\Turn;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Exception\ActivityFailed;
use Phalanx\Athena\Exception\MaxInvocationsReached;
use Phalanx\Athena\Hook\StepContext;
use Phalanx\Athena\Hook\StepHook;
use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Record\Message;
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
    ) {
    }

    public function __invoke(TaskScope $scope, Agent $agent, Activity\Config $config, ?Log $log = null): Activity\Result
    {
        $current = $log ?? Log::from([]);
        $turn    = new Config($config->id, $config->context, $config->maxInvocations);
        $runtime = ($this->runtimeFactory)($scope);
        $hooks   = [...$this->hooks, ...$config->hooks];
        $cues    = [];

        for ($i = 1; $i <= $config->maxInvocations; $i++) {
            $scope->throwIfCancelled();
            $runtime->throwIfCancelled();

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
            $stream  = CompositeStream::wrap($this->provider->perform($invocation, $runtime), $scope);

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
                    [$outcome, $error] = self::effectOutcome($cue);
                    break;
                }
            }

            if ($text !== '') {
                $current = self::appendAssistantMessage($current, $text);
            }

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

    private static function appendAssistantMessage(Log $log, string $text): Log
    {
        $records = $log->toArray();
        $records[] = new Message(
            id: 'msg_' . Id::generate(),
            sequence: count($records) + 1,
            at: new \DateTimeImmutable(),
            role: 'assistant',
            text: $text,
        );

        return Log::from($records);
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
    ): \Phalanx\Athena\Hook\StepHookResult {
        if ($hooks === []) {
            return \Phalanx\Athena\Hook\StepHookResult::continue();
        }

        foreach ($hooks as $hook) {
            $result = $hook($scope, $context);
            if ($result->outcome !== Outcome::Continue) {
                return $result;
            }
        }

        return \Phalanx\Athena\Hook\StepHookResult::continue();
    }
}
