<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit;

use Phalanx\Athena\Activity\Activity;
use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\Activity\Executor;
use Phalanx\Athena\Activity\Result;
use Phalanx\Athena\Activity\State;
use Phalanx\Athena\Testing\ScopeStub;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Cancelled;
use Phalanx\Panoply\Cue\Activity\Completed;
use Phalanx\Panoply\Cue\Activity\Failed;
use Phalanx\Panoply\Cue\Activity\Started;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Stream;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActivityTest extends TestCase
{
    #[Test]
    public function completedActivityEmitsStartedAndCompletedCues(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T14:00:00Z');
        $innerCues = [
            new TokenDelta('cue_1', 1, 'act_1', null, 'agent_1', $at, 'hello'),
            new TokenStop('cue_2', 2, 'act_1', null, 'agent_1', $at, StopReason::EndOfTurn),
        ];

        $executor = self::stubExecutor(State::Completed, Outcome::Complete, $innerCues);
        $activity = new Activity($executor);
        $config = new Config('act_1', Context::new());
        $result = $activity(new ScopeStub(), new TestAgent(), $config);

        $cues = $result->stream->toArray();
        $types = array_map(static fn(Cue $c): string => $c::class, $cues);

        self::assertSame(State::Completed, $result->state);
        self::assertSame(Outcome::Complete, $result->outcome);

        self::assertContains(Started::class, $types);
        self::assertContains(Completed::class, $types);
    }

    #[Test]
    public function failedExecutorProducesFailedStateWithCues(): void
    {
        $executor = self::throwingExecutor(new \RuntimeException('boom'));
        $activity = new Activity($executor);
        $config = new Config('act_1', Context::new());
        $result = $activity(new ScopeStub(), new TestAgent(), $config);

        self::assertSame(State::Failed, $result->state);
        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertNotNull($result->error);

        $cues = $result->stream->toArray();
        $types = array_map(static fn(Cue $c): string => $c::class, $cues);

        self::assertContains(Started::class, $types);
        self::assertContains(Cue\Activity\Failed::class, $types);
    }

    #[Test]
    public function cancelledScopeProducesCancelledResult(): void
    {
        $executor = self::throwingExecutor(new \Phalanx\Cancellation\Cancelled('cancelled'));
        $activity = new Activity($executor);
        $config = new Config('act_1', Context::new());
        $result = $activity(new ScopeStub(), new TestAgent(), $config);

        self::assertSame(State::Cancelled, $result->state);
        self::assertSame(Outcome::Cancelled, $result->outcome);

        $cues = $result->stream->toArray();
        $types = array_map(static fn(Cue $c): string => $c::class, $cues);

        self::assertContains(Cue\Activity\Cancelled::class, $types);
    }

    #[Test]
    public function lifecycleCuesHaveSurroundingSequenceNumbers(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T14:00:00Z');
        $innerCues = [
            new TokenDelta('cue_1', 10, 'act_1', null, 'agent_1', $at, 'data'),
            new TokenStop('cue_2', 20, 'act_1', null, 'agent_1', $at, StopReason::EndOfTurn),
        ];

        $executor = self::stubExecutor(State::Completed, Outcome::Complete, $innerCues);
        $activity = new Activity($executor);
        $config = new Config('act_1', Context::new());
        $result = $activity(new ScopeStub(), new TestAgent(), $config);

        $cues = $result->stream->toArray();
        $started = array_filter($cues, static fn(Cue $c): bool => $c instanceof Started);
        $completed = array_filter($cues, static fn(Cue $c): bool => $c instanceof Completed);

        self::assertCount(1, $started);
        self::assertCount(1, $completed);

        $startedCue = array_values($started)[0];
        $completedCue = array_values($completed)[0];

        self::assertLessThan(10, $startedCue->sequence);
        self::assertGreaterThan(20, $completedCue->sequence);
    }

    #[Test]
    public function emptyInnerStreamStillProducesLifecycleCues(): void
    {
        $executor = self::stubExecutor(State::Completed, Outcome::Complete, []);
        $activity = new Activity($executor);
        $config = new Config('act_empty', Context::new());
        $result = $activity(new ScopeStub(), new TestAgent(), $config);

        $cues = $result->stream->toArray();
        $types = array_map(static fn(Cue $c): string => $c::class, $cues);

        self::assertSame(State::Completed, $result->state);
        self::assertContains(Started::class, $types);
        self::assertContains(Completed::class, $types);
        self::assertCount(2, $cues);
    }

    #[Test]
    public function cancelledResultSequenceStartsAtOne(): void
    {
        $executor = self::throwingExecutor(new \Phalanx\Cancellation\Cancelled('cancelled'));
        $activity = new Activity($executor);
        $config = new Config('act_cancel', Context::new());
        $result = $activity(new ScopeStub(), new TestAgent(), $config);

        $cues = $result->stream->toArray();
        $started = array_values(array_filter($cues, static fn(Cue $c): bool => $c instanceof Started));
        $cancelled = array_values(array_filter($cues, static fn(Cue $c): bool => $c instanceof Cancelled));

        self::assertCount(1, $started);
        self::assertCount(1, $cancelled);
        self::assertSame(1, $started[0]->sequence);
        self::assertSame(2, $cancelled[0]->sequence);
    }

    #[Test]
    public function failedResultSequenceStartsAtOne(): void
    {
        $executor = self::throwingExecutor(new \RuntimeException('boom'));
        $activity = new Activity($executor);
        $config = new Config('act_fail', Context::new());
        $result = $activity(new ScopeStub(), new TestAgent(), $config);

        $cues = $result->stream->toArray();
        $started = array_values(array_filter($cues, static fn(Cue $c): bool => $c instanceof Started));
        $failed = array_values(array_filter($cues, static fn(Cue $c): bool => $c instanceof Failed));

        self::assertCount(1, $started);
        self::assertCount(1, $failed);
        self::assertSame(1, $started[0]->sequence);
        self::assertSame(2, $failed[0]->sequence);
    }

    /**
     * @param list<Cue> $innerCues
     */
    private static function stubExecutor(State $state, Outcome $outcome, array $innerCues = []): Executor
    {
        return new class ($state, $outcome, $innerCues) implements Executor {
            /** @param list<Cue> $innerCues */
            public function __construct(
                private State $state,
                private Outcome $outcome,
                private array $innerCues,
            ) {
            }

            public function __invoke(TaskScope $scope, Agent $agent, Config $config, ?Log $log = null): Result
            {
                return new Result(
                    activityId: $config->id,
                    state: $this->state,
                    outcome: $this->outcome,
                    log: $log ?? Log::from([]),
                    invocations: 1,
                    stream: Stream::from($this->innerCues),
                );
            }
        };
    }

    private static function throwingExecutor(\Throwable $error): Executor
    {
        return new class ($error) implements Executor {
            public function __construct(private \Throwable $error)
            {
            }

            public function __invoke(TaskScope $scope, Agent $agent, Config $config, ?Log $log = null): Result
            {
                throw $this->error;
            }
        };
    }
}
