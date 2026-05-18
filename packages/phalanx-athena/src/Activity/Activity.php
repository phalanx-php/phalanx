<?php

declare(strict_types=1);

namespace Phalanx\Athena\Activity;

use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Cancellation\Cancelled as ScopeCancelled;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity as ActivityCue;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Stream;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Task\Task;

final class Activity implements Executor
{
    public function __construct(
        private Executor $executor,
    ) {
    }

    public function __invoke(TaskScope $scope, Agent $agent, Config $config, ?Log $log = null): Result
    {
        if (!$scope instanceof ExecutionScope) {
            return $this->runInline($scope, $agent, $config, $log);
        }

        $self = $this;

        try {
            return $scope->execute(Task::named(
                'athena.activity.' . $config->id,
                static fn(ExecutionScope $activityScope): Result => $self->runInline($activityScope, $agent, $config, $log),
            ));
        } catch (ScopeCancelled $error) {
            return self::cancelled($scope, $config, $log, $error);
        }
    }

    private static function withLifecycle(TaskScope $scope, Config $config, Agent $agent, Result $result): Result
    {
        $innerCues = $result->stream->toArray();
        $composite = CompositeStream::wrap($scope, Stream::from($innerCues));
        $composite->emit(self::started($config, $agent, $innerCues));

        $terminal = self::terminalCue($config, $agent, $result, $innerCues);
        if ($terminal !== null) {
            $composite->emit($terminal);
        }

        return new Result(
            activityId: $result->activityId,
            state: $result->state,
            outcome: $result->outcome,
            log: $result->log,
            invocations: $result->invocations,
            error: $result->error,
            stream: $composite->stream(),
        );
    }

    private static function cancelled(TaskScope $scope, Config $config, ?Log $log, ScopeCancelled $error): Result
    {
        $composite = CompositeStream::wrap($scope, Stream::from([]));
        $composite->emit(new ActivityCue\Started(
            id: 'cue_' . Id::generate(),
            sequence: 1,
            activityId: $config->id,
            invocationId: null,
            agentId: null,
            at: new \DateTimeImmutable(),
        ));
        $composite->emit(new ActivityCue\Cancelled(
            id: 'cue_' . Id::generate(),
            sequence: 2,
            activityId: $config->id,
            invocationId: null,
            agentId: null,
            at: new \DateTimeImmutable(),
            reason: $error->getMessage(),
        ));

        return new Result(
            activityId: $config->id,
            state: State::Cancelled,
            outcome: Outcome::Cancelled,
            log: $log ?? Log::from([]),
            invocations: 0,
            error: $error,
            stream: $composite->stream(),
        );
    }

    private static function failed(TaskScope $scope, Config $config, ?Log $log, \Throwable $error): Result
    {
        $composite = CompositeStream::wrap($scope, Stream::from([]));
        $composite->emit(new ActivityCue\Started(
            id: 'cue_' . Id::generate(),
            sequence: 1,
            activityId: $config->id,
            invocationId: null,
            agentId: null,
            at: new \DateTimeImmutable(),
        ));
        $composite->emit(new ActivityCue\Failed(
            id: 'cue_' . Id::generate(),
            sequence: 2,
            activityId: $config->id,
            invocationId: null,
            agentId: null,
            at: new \DateTimeImmutable(),
            reason: $error->getMessage(),
            errorClass: $error::class,
        ));

        return new Result(
            activityId: $config->id,
            state: State::Failed,
            outcome: Outcome::Failed,
            log: $log ?? Log::from([]),
            invocations: 0,
            error: $error,
            stream: $composite->stream(),
        );
    }

    /**
     * @param list<Cue> $innerCues
     */
    private static function started(Config $config, Agent $agent, array $innerCues): ActivityCue\Started
    {
        return new ActivityCue\Started(
            id: 'cue_' . Id::generate(),
            sequence: self::startingSequence($innerCues),
            activityId: $config->id,
            invocationId: null,
            agentId: $agent->id,
            at: new \DateTimeImmutable(),
        );
    }

    /**
     * @param list<Cue> $innerCues
     */
    private static function terminalCue(Config $config, Agent $agent, Result $result, array $innerCues): ?Cue
    {
        return match ($result->state) {
            State::Completed => new ActivityCue\Completed(
                id: 'cue_' . Id::generate(),
                sequence: self::endingSequence($innerCues),
                activityId: $config->id,
                invocationId: null,
                agentId: $agent->id,
                at: new \DateTimeImmutable(),
            ),
            State::Failed => new ActivityCue\Failed(
                id: 'cue_' . Id::generate(),
                sequence: self::endingSequence($innerCues),
                activityId: $config->id,
                invocationId: null,
                agentId: $agent->id,
                at: new \DateTimeImmutable(),
                reason: $result->error?->getMessage() ?? 'activity failed',
                errorClass: $result->error !== null ? $result->error::class : null,
            ),
            State::Cancelled => new ActivityCue\Cancelled(
                id: 'cue_' . Id::generate(),
                sequence: self::endingSequence($innerCues),
                activityId: $config->id,
                invocationId: null,
                agentId: $agent->id,
                at: new \DateTimeImmutable(),
                reason: $result->error?->getMessage() ?? 'activity cancelled',
            ),
            default => null,
        };
    }

    /** @param list<Cue> $cues */
    private static function startingSequence(array $cues): int
    {
        if ($cues === []) {
            return 1;
        }

        return min(array_map(static fn(Cue $cue): int => $cue->sequence, $cues)) - 1;
    }

    /** @param list<Cue> $cues */
    private static function endingSequence(array $cues): int
    {
        if ($cues === []) {
            return 2;
        }

        return max(array_map(static fn(Cue $cue): int => $cue->sequence, $cues)) + 1;
    }

    private function runInline(TaskScope $scope, Agent $agent, Config $config, ?Log $log): Result
    {
        try {
            $result = ($this->executor)($scope, $agent, $config, $log);
        } catch (ScopeCancelled $error) {
            return self::cancelled($scope, $config, $log, $error);
        } catch (\Throwable $error) {
            return self::failed($scope, $config, $log, $error);
        }

        return self::withLifecycle($scope, $config, $agent, $result);
    }
}
