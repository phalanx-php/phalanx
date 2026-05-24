<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Provider\Fake\Provider;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TurnLoopRuntimeTest extends PhalanxTestCase
{
    #[Test]
    public function turnLoopRunsInsideAegisScopeWithoutRuntimeLeaks(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new TokenDelta('cue_1', 1, 'act_1', null, 'athena-test-agent', $at, 'scoped'),
            new TokenStop('cue_2', 2, 'act_1', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($provider): Activity\Result {
            $loop = new Loop(new DefaultBuilder(), $provider);

            return $loop($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 1));
        });

        self::assertSame(Activity\State::Completed, $result->state);
        self::assertSame(Outcome::Complete, $result->outcome);
        $this->scope->expect->runtime()->clean();
    }

    #[Test]
    public function streamingTurnLoopKeepsThinkingOutOfConversationLog(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new TokenDelta('cue_1', 1, 'act_1', null, 'athena-test-agent', $at, 'thinking ', Channel::Thinking),
            new TokenDelta('cue_2', 2, 'act_1', null, 'athena-test-agent', $at, 'reasoning ', Channel::Reasoning),
            new TokenDelta('cue_3', 3, 'act_1', null, 'athena-test-agent', $at, 'answer', Channel::Message),
            new TokenStop('cue_4', 4, 'act_1', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        [$text, $types, $deltas] = $this->scope->run(static function (ExecutionScope $scope) use ($provider): array {
            $loop = new Loop(new DefaultBuilder(), $provider);
            $result = $loop($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 1));
            $cues = $result->stream->toArray();
            $types = array_map(static fn($cue): string => $cue->type, $cues);
            $deltas = [];

            foreach ($cues as $cue) {
                if (!$cue instanceof TokenDelta) {
                    continue;
                }

                $deltas[] = [$cue->channel, $cue->text];
            }

            $records = $result->log->toArray();

            return [$records[0] instanceof Message ? $records[0]->text : null, $types, $deltas];
        });

        self::assertSame('answer', $text);
        self::assertSame(
            [
                [Channel::Thinking, 'thinking '],
                [Channel::Reasoning, 'reasoning '],
                [Channel::Message, 'answer'],
            ],
            $deltas,
        );
        self::assertSame(
            [
                'cue.output.token_delta',
                'cue.output.token_delta',
                'cue.output.token_delta',
                'cue.output.token_stop',
            ],
            $types,
        );
        $this->scope->expect->runtime()->clean();
    }

    #[Test]
    public function activityRunsTurnLoopAsSupervisedAegisTaskAndEmitsLifecycleCues(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new TokenDelta('cue_1', 1, 'act_1', null, 'athena-test-agent', $at, 'scoped'),
            new TokenStop('cue_2', 2, 'act_1', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        [$types, $state] = $this->scope->run(static function (ExecutionScope $scope) use ($provider): array {
            $activity = new Activity\Activity(new Loop(new DefaultBuilder(), $provider));
            $result = $activity($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 1));
            $types = array_map(static fn($cue): string => $cue->type, $result->stream->toArray());

            return [$types, $result->state];
        });

        self::assertSame(
            [
                'cue.activity.started',
                'cue.output.token_delta',
                'cue.output.token_stop',
                'cue.activity.completed',
            ],
            $types,
        );
        self::assertSame(Activity\State::Completed, $state);
        $this->scope->expect->runtime()->clean();
    }

    #[Test]
    public function activityMapsInnerFailureToFailedLifecycleCue(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new Requested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: null,
                agentId: 'athena-test-agent',
                at: $at,
                effectId: 'eff_1',
                kind: Kind::FileRead,
                summary: 'read file',
            ),
        ], Capabilities::empty());

        [$types, $state, $error] = $this->scope->run(static function (ExecutionScope $scope) use ($provider): array {
            $activity = new Activity\Activity(new Loop(new DefaultBuilder(), $provider));
            $result = $activity($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 1));
            $types = array_map(static fn($cue): string => $cue->type, $result->stream->toArray());

            return [$types, $result->state, $result->error];
        });

        self::assertContains('cue.activity.failed', $types);
        self::assertSame(Activity\State::Failed, $state);
        self::assertNotNull($error);
        $this->scope->expect->runtime()->clean();
    }
}
