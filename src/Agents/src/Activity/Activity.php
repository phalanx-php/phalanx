<?php

declare(strict_types=1);

namespace Phalanx\Agents\Activity;

use Phalanx\Agents\Turn\Outcome;
use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Cue;
use Phalanx\AiProviders\Cue\Activity as ActivityCue;
use Phalanx\AiProviders\Id;
use Phalanx\AiProviders\Stream;
use Phalanx\Cancellation\Cancelled as ScopeCancelled;
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
            return self::runInline($this->executor, $scope, $agent, $config, $log);
        }

        $executor = $this->executor;

        try {
            return $scope->execute(Task::named(
                'agent.activity.' . $config->id,
                static fn(ExecutionScope $activityScope): Result
                    => self::runInline($executor, $activityScope, $agent, $config, $log),
            ));
        } catch (ScopeCancelled $error) {
            return self::cancelled($config, $log, $error);
        }
    }

    private static function withLifecycle(Config $config, Agent $agent, Result $result): Result
    {
        $outerCell = new TerminalCell();
        $innerResult = $result;

        $stream = new Stream(static function () use ($config, $agent, $innerResult, $outerCell): \Generator {
            yield new ActivityCue\Started(
                id: 'cue_' . Id::generate(),
                sequence: 0,
                activityId: $config->id,
                invocationId: null,
                agentId: $agent->id,
                at: new \DateTimeImmutable(),
            );

            $maxSeq = 0;
            try {
                foreach ($innerResult->stream as $cue) {
                    if ($cue->sequence > $maxSeq) {
                        $maxSeq = $cue->sequence;
                    }
                    yield $cue;
                }

                $terminal = self::terminalCue($config, $agent, $innerResult, $maxSeq + 1);
                if ($terminal !== null) {
                    yield $terminal;
                }

                $outerCell->resolve(new TerminalState(
                    state: $innerResult->state,
                    outcome: $innerResult->outcome,
                    log: $innerResult->log,
                    invocations: $innerResult->invocations,
                    error: $innerResult->error,
                ));
                unset($innerResult);
            } catch (\Throwable $e) {
                $outerCell->resolve(new TerminalState(
                    state: $innerResult->state,
                    outcome: $innerResult->outcome,
                    log: $innerResult->log,
                    invocations: $innerResult->invocations,
                    error: $e,
                ));
                unset($innerResult);

                throw $e;
            }
        });

        return Result::lazy($result->activityId, $stream, $outerCell);
    }

    private static function cancelled(Config $config, ?Log $log, ScopeCancelled $error): Result
    {
        return new Result(
            activityId: $config->id,
            state: State::Cancelled,
            outcome: Outcome::Cancelled,
            log: $log ?? Log::from([]),
            invocations: 0,
            error: $error,
            stream: Stream::from([
                new ActivityCue\Started(
                    id: 'cue_' . Id::generate(),
                    sequence: 1,
                    activityId: $config->id,
                    invocationId: null,
                    agentId: null,
                    at: new \DateTimeImmutable(),
                ),
                new ActivityCue\Cancelled(
                    id: 'cue_' . Id::generate(),
                    sequence: 2,
                    activityId: $config->id,
                    invocationId: null,
                    agentId: null,
                    at: new \DateTimeImmutable(),
                    reason: $error->getMessage(),
                ),
            ]),
        );
    }

    private static function failed(Config $config, ?Log $log, \Throwable $error): Result
    {
        return new Result(
            activityId: $config->id,
            state: State::Failed,
            outcome: Outcome::Failed,
            log: $log ?? Log::from([]),
            invocations: 0,
            error: $error,
            stream: Stream::from([
                new ActivityCue\Started(
                    id: 'cue_' . Id::generate(),
                    sequence: 1,
                    activityId: $config->id,
                    invocationId: null,
                    agentId: null,
                    at: new \DateTimeImmutable(),
                ),
                new ActivityCue\Failed(
                    id: 'cue_' . Id::generate(),
                    sequence: 2,
                    activityId: $config->id,
                    invocationId: null,
                    agentId: null,
                    at: new \DateTimeImmutable(),
                    reason: $error->getMessage(),
                    errorClass: $error::class,
                ),
            ]),
        );
    }

    private static function terminalCue(Config $config, Agent $agent, Result $result, int $sequence): ?Cue
    {
        return match ($result->state) {
            State::Completed => new ActivityCue\Completed(
                id: 'cue_' . Id::generate(),
                sequence: $sequence,
                activityId: $config->id,
                invocationId: null,
                agentId: $agent->id,
                at: new \DateTimeImmutable(),
            ),
            State::Failed => new ActivityCue\Failed(
                id: 'cue_' . Id::generate(),
                sequence: $sequence,
                activityId: $config->id,
                invocationId: null,
                agentId: $agent->id,
                at: new \DateTimeImmutable(),
                reason: $result->error?->getMessage() ?? 'activity failed',
                errorClass: $result->error !== null ? $result->error::class : null,
            ),
            State::Cancelled => new ActivityCue\Cancelled(
                id: 'cue_' . Id::generate(),
                sequence: $sequence,
                activityId: $config->id,
                invocationId: null,
                agentId: $agent->id,
                at: new \DateTimeImmutable(),
                reason: $result->error?->getMessage() ?? 'activity cancelled',
            ),
            default => null,
        };
    }

    private static function runInline(
        Executor $executor,
        TaskScope $scope,
        Agent $agent,
        Config $config,
        ?Log $log,
    ): Result {
        try {
            $result = ($executor)($scope, $agent, $config, $log);
        } catch (ScopeCancelled $error) {
            return self::cancelled($config, $log, $error);
        } catch (\Throwable $error) {
            return self::failed($config, $log, $error);
        }

        return self::withLifecycle($config, $agent, $result);
    }
}
