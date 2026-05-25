<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Agent;

use DateTimeImmutable;
use Phalanx\Harness\Agent\AgentExecutorContract;
use Phalanx\Harness\Agent\MockAgentExecutor;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Completed;
use Phalanx\Panoply\Cue\Activity\Started;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MockAgentExecutorTest extends TestCase
{
    #[Test]
    public function implementsContract(): void
    {
        self::assertInstanceOf(AgentExecutorContract::class, new MockAgentExecutor());
    }

    #[Test]
    public function sendYieldsAllScriptedCues(): void
    {
        $cues = self::olympusCues();
        $executor = new MockAgentExecutor($cues);

        $collected = iterator_to_array($executor->send('Hail Olympus'), false);

        self::assertCount(count($cues), $collected);
        self::assertSame($cues, $collected);
    }

    #[Test]
    public function sendWithEmptyScriptYieldsNothing(): void
    {
        $executor = new MockAgentExecutor([]);

        $collected = iterator_to_array($executor->send('Spartans!'), false);

        self::assertSame([], $collected);
    }

    #[Test]
    public function cancelStopsMidStream(): void
    {
        $executor = new MockAgentExecutor(self::olympusCues());

        $collected = [];
        foreach ($executor->send('March to Thermopylae') as $cue) {
            $collected[] = $cue;
            $executor->cancel();
        }

        self::assertCount(1, $collected);
    }

    #[Test]
    public function withCuesReturnsNewInstanceWithDifferentScript(): void
    {
        $original = new MockAgentExecutor([]);
        $delta = new TokenDelta(
            id: 'cue_01',
            sequence: 1,
            activityId: 'act_apollo',
            invocationId: null,
            agentId: null,
            at: new DateTimeImmutable(),
            text: 'Know thyself',
        );

        $copy = $original->withCues([$delta]);

        self::assertNotSame($original, $copy);
        self::assertSame([], iterator_to_array($original->send(''), false));
        self::assertCount(1, iterator_to_array($copy->send(''), false));
    }

    #[Test]
    public function multipleSendCallsEachReplayFullScript(): void
    {
        $cues = self::olympusCues();
        $executor = new MockAgentExecutor($cues);

        $first = iterator_to_array($executor->send('First'), false);
        $second = iterator_to_array($executor->send('Second'), false);

        self::assertCount(count($cues), $first);
        self::assertCount(count($cues), $second);
        self::assertSame($first, $second);
    }

    #[Test]
    public function cancelThenSendReplaysFullScript(): void
    {
        $cues = self::olympusCues();
        $executor = new MockAgentExecutor($cues);

        foreach ($executor->send('Charge') as $_) {
            $executor->cancel();
            break;
        }

        $replay = iterator_to_array($executor->send('Rally'), false);

        self::assertCount(count($cues), $replay);
    }

    #[Test]
    public function cueTypesArePreserved(): void
    {
        $cues = self::olympusCues();
        $executor = new MockAgentExecutor($cues);

        $collected = iterator_to_array($executor->send('Inspect'), false);

        self::assertInstanceOf(Started::class, $collected[0]);
        self::assertInstanceOf(TokenDelta::class, $collected[1]);
        self::assertInstanceOf(TokenStop::class, $collected[2]);
        self::assertInstanceOf(Completed::class, $collected[3]);
    }

    #[Test]
    public function sendWithThinkingChannelCuePreservesChannel(): void
    {
        $delta = new TokenDelta(
            id: 'cue_01',
            sequence: 1,
            activityId: 'act_zeus',
            invocationId: null,
            agentId: null,
            at: new DateTimeImmutable(),
            text: 'Pondering the fate of Sparta...',
            channel: Channel::Thinking,
        );
        $executor = new MockAgentExecutor([$delta]);

        /** @var list<Cue> $collected */
        $collected = iterator_to_array($executor->send('Zeus thinks'), false);

        self::assertInstanceOf(TokenDelta::class, $collected[0]);
        self::assertSame(Channel::Thinking, $collected[0]->channel);
    }

    /**
     * @return list<Cue>
     */
    private static function olympusCues(): array
    {
        $at = new DateTimeImmutable();

        return [
            new Started(
                id: 'cue_start',
                sequence: 1,
                activityId: 'act_apollo',
                invocationId: null,
                agentId: 'agent_apollo',
                at: $at,
            ),
            new TokenDelta(
                id: 'cue_delta',
                sequence: 2,
                activityId: 'act_apollo',
                invocationId: null,
                agentId: 'agent_apollo',
                at: $at,
                text: 'The oracle speaks from Delphi.',
            ),
            new TokenStop(
                id: 'cue_stop',
                sequence: 3,
                activityId: 'act_apollo',
                invocationId: null,
                agentId: 'agent_apollo',
                at: $at,
                reason: StopReason::EndOfTurn,
            ),
            new Completed(
                id: 'cue_complete',
                sequence: 4,
                activityId: 'act_apollo',
                invocationId: null,
                agentId: 'agent_apollo',
                at: $at,
            ),
        ];
    }
}
