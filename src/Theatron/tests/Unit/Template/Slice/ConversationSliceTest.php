<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Slice;

use DateTimeImmutable;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Theatron\Template\Slice\ConversationSlice;
use Phalanx\Theatron\Template\Slice\ConversationTurnStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationSliceTest extends TestCase
{
    #[Test]
    public function defaultStateIsEmptyAndNotStreaming(): void
    {
        $slice = new ConversationSlice();

        self::assertSame([], $slice->messages);
        self::assertSame([], $slice->turns);
        self::assertFalse($slice->isStreaming);
        self::assertSame('', $slice->thinkingBuffer);
    }

    #[Test]
    public function addUserMessageAddsCompleteUserMessage(): void
    {
        $slice = new ConversationSlice()->addUserMessage('Hello, Leonidas.');

        self::assertCount(1, $slice->messages);
        self::assertSame('user', $slice->messages[0]->role);
        self::assertSame('Hello, Leonidas.', $slice->messages[0]->text);
        self::assertNull($slice->messages[0]->channel);
        self::assertTrue($slice->messages[0]->complete);
        self::assertCount(1, $slice->turns);
        self::assertSame('turn_1', $slice->turns[0]->id);
        self::assertSame('Hello, Leonidas.', $slice->turns[0]->userText);
        self::assertSame(ConversationTurnStatus::Running, $slice->turns[0]->status);
    }

    #[Test]
    public function appendTokenOnEmptyCreatesNewAssistantMessage(): void
    {
        $slice = new ConversationSlice()->appendToken('The phalanx holds.');

        self::assertCount(1, $slice->messages);
        self::assertSame('assistant', $slice->messages[0]->role);
        self::assertSame('The phalanx holds.', $slice->messages[0]->text);
        self::assertSame('message', $slice->messages[0]->channel);
        self::assertFalse($slice->messages[0]->complete);
        self::assertTrue($slice->isStreaming);
        self::assertCount(1, $slice->turns);
        self::assertSame('The phalanx holds.', $slice->turns[0]->assistantText());
    }

    #[Test]
    public function appendTokenOnSameChannelAppendsToIncompleteMessage(): void
    {
        $slice = new ConversationSlice()
            ->appendToken('Sparta ')
            ->appendToken('stands.');

        self::assertCount(1, $slice->messages);
        self::assertSame('Sparta stands.', $slice->messages[0]->text);
        self::assertFalse($slice->messages[0]->complete);
    }

    #[Test]
    public function appendTokenOnDifferentChannelCreatesNewMessage(): void
    {
        $slice = new ConversationSlice()
            ->appendToken('deliberating...', 'thinking')
            ->appendToken('The answer is: hold.', 'message');

        self::assertCount(2, $slice->messages);
        self::assertSame('thinking', $slice->messages[0]->channel);
        self::assertSame('message', $slice->messages[1]->channel);
        self::assertSame('deliberating...', $slice->turns[0]->thinkingText());
        self::assertSame('The answer is: hold.', $slice->turns[0]->assistantText());
    }

    #[Test]
    public function finalizeMessageMarksLastMessageCompleteAndClearsStreaming(): void
    {
        $slice = new ConversationSlice()
            ->appendToken('Thermopylae.')
            ->finalizeMessage();

        self::assertCount(1, $slice->messages);
        self::assertTrue($slice->messages[0]->complete);
        self::assertFalse($slice->isStreaming);
        self::assertSame('', $slice->thinkingBuffer);
        self::assertSame(ConversationTurnStatus::Completed, $slice->turns[0]->status);
    }

    #[Test]
    public function appendTokenOnThinkingChannelPopulatesThinkingBuffer(): void
    {
        $slice = new ConversationSlice()
            ->appendToken('considering ', 'thinking')
            ->appendToken('options', 'thinking');

        self::assertSame('considering options', $slice->thinkingBuffer);
    }

    #[Test]
    public function sliceIsCopyOnModify(): void
    {
        $original = new ConversationSlice();
        $modified = $original->addUserMessage('test');

        self::assertSame([], $original->messages);
        self::assertSame([], $original->turns);
        self::assertCount(1, $modified->messages);
        self::assertCount(1, $modified->turns);
        self::assertNotSame($original, $modified);
    }

    #[Test]
    public function appendCuePreservesOrderedPanoplyEventsInActiveTurn(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('What did the model think?')
            ->appendCue(new TokenDelta('cue_1', 1, 'act_1', 'inv_1', 'agent_1', $at, 'consider ', Channel::Thinking))
            ->appendCue(new TokenDelta('cue_2', 2, 'act_1', 'inv_1', 'agent_1', $at, 'reason ', Channel::Reasoning))
            ->appendCue(new TokenDelta('cue_3', 3, 'act_1', 'inv_1', 'agent_1', $at, 'answer', Channel::Message))
            ->appendCue(new TokenStop('cue_4', 4, 'act_1', 'inv_1', 'agent_1', $at, StopReason::EndOfTurn));

        self::assertCount(1, $slice->turns);
        self::assertSame('consider reason ', $slice->turns[0]->thinkingText());
        self::assertSame('answer', $slice->turns[0]->assistantText());
        self::assertSame(ConversationTurnStatus::Completed, $slice->turns[0]->status);
        $ids = [];
        foreach ($slice->turns[0]->events as $event) {
            $ids[] = $event->id;
        }

        $channels = [];
        foreach ($slice->turns[0]->events as $event) {
            $channels[] = $event->channel;
        }

        self::assertSame(['cue_1', 'cue_2', 'cue_3', 'cue_4'], $ids);
        self::assertSame([Channel::Thinking, Channel::Reasoning, Channel::Message, Channel::Message], $channels);
    }

    #[Test]
    public function effectCueMarksTurnAwaitingApprovalWithoutLosingEvent(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('Read a file')
            ->appendCue(new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                kind: Kind::FileRead,
                summary: 'Read a strategy note',
                requiresApproval: true,
            ));

        self::assertCount(1, $slice->turns);
        self::assertCount(1, $slice->turns[0]->events);
        self::assertSame('cue_1', $slice->turns[0]->events[0]->id);
        self::assertSame(ConversationTurnStatus::AwaitingApproval, $slice->turns[0]->status);
    }
}
