<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Slice;

use DateTimeImmutable;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta as EffectArgumentsDelta;
use Phalanx\Panoply\Cue\Effect\Authorized as EffectAuthorized;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Invocation\Failed as InvocationFailed;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Runtime\Error as RuntimeError;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Theatron\Template\Slice\ConversationSlice;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventKind;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventSeverity;
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

    #[Test]
    public function cueEventsExposeTypedProjectionForThreadRendering(): void
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
                arguments: ['path' => 'notes/strategy.md'],
                requiresApproval: true,
            ))
            ->appendCue(new EffectArgumentsDelta(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                jsonDelta: '{"path"',
            ))
            ->appendCue(new EffectAuthorized(
                id: 'cue_3',
                sequence: 3,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                grantId: 'grant_1',
            ))
            ->appendCue(new EffectExecuted(
                id: 'cue_4',
                sequence: 4,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                durationMs: 42,
                resultDigest: 'sha256:abc',
            ))
            ->appendCue(new FinalUsage(
                id: 'cue_5',
                sequence: 5,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                inputTokens: 150,
                outputTokens: 300,
                cacheReadTokens: 10,
                cacheWriteTokens: 20,
                costUsd: 0.04,
            ));

        $events = $slice->turns[0]->projectionEvents();

        self::assertCount(5, $events);
        self::assertSame(ConversationTurnEventKind::EffectRequested, $events[0]->projection->kind);
        self::assertSame(ConversationTurnEventSeverity::Warning, $events[0]->projection->severity);
        self::assertSame('eff_1', $events[0]->projection->effectId);
        self::assertSame('file.read', $events[0]->projection->effectKind);
        self::assertSame(['path' => 'notes/strategy.md'], $events[0]->projection->arguments);
        self::assertSame(ConversationTurnEventKind::EffectArgumentsDelta, $events[1]->projection->kind);
        self::assertSame('{"path"', $events[1]->projection->argumentsDelta);
        self::assertSame(ConversationTurnEventKind::EffectAuthorized, $events[2]->projection->kind);
        self::assertSame('grant_1', $events[2]->projection->grantId);
        self::assertSame(ConversationTurnEventKind::EffectExecuted, $events[3]->projection->kind);
        self::assertSame(42, $events[3]->projection->durationMs);
        self::assertSame('sha256:abc', $events[3]->projection->resultDigest);
        self::assertSame(ConversationTurnEventKind::UsageFinal, $events[4]->projection->kind);
        self::assertSame(150, $events[4]->projection->inputTokens);
        self::assertSame(300, $events[4]->projection->outputTokens);
        self::assertSame(450, $events[4]->projection->usageTotal());
        self::assertSame(0.04, $events[4]->projection->costUsd);
    }

    #[Test]
    public function failureAndRuntimeCuesExposeErrorProjection(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('Run risky work')
            ->appendCue(new EffectFailed(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                reason: 'Permission denied',
                errorClass: 'RuntimeException',
            ))
            ->appendCue(new RuntimeError(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                message: 'Provider stream failed',
                code: 'provider.stream',
                errorClass: 'TransportException',
            ))
            ->appendCue(new InvocationFailed(
                id: 'cue_3',
                sequence: 3,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                reason: 'Invocation failed',
                errorClass: 'ProviderException',
            ))
            ->appendCue(new ActivityFailed(
                id: 'cue_4',
                sequence: 4,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                reason: 'Activity failed',
                errorClass: 'ActivityException',
            ));

        $events = $slice->turns[0]->projectionEvents();

        self::assertCount(4, $events);
        self::assertSame(ConversationTurnEventKind::EffectFailed, $events[0]->projection->kind);
        self::assertSame(ConversationTurnEventSeverity::Error, $events[0]->projection->severity);
        self::assertSame('Permission denied', $events[0]->projection->reason);
        self::assertSame('RuntimeException', $events[0]->projection->errorClass);
        self::assertSame(ConversationTurnEventKind::RuntimeError, $events[1]->projection->kind);
        self::assertSame('provider.stream', $events[1]->projection->reason);
        self::assertSame('TransportException', $events[1]->projection->errorClass);
        self::assertSame(ConversationTurnEventKind::InvocationFailed, $events[2]->projection->kind);
        self::assertSame('Invocation failed', $events[2]->projection->reason);
        self::assertSame('ProviderException', $events[2]->projection->errorClass);
        self::assertSame(ConversationTurnEventKind::ActivityFailed, $events[3]->projection->kind);
        self::assertSame(ConversationTurnStatus::Failed, $slice->turns[0]->status);
    }

    #[Test]
    public function normalStopReasonsStayOutOfThreadProjection(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('Stop normally')
            ->appendCue(new TokenDelta('cue_1', 1, 'act_1', 'inv_1', 'agent_1', $at, 'answer', Channel::Message))
            ->appendCue(new TokenStop('cue_2', 2, 'act_1', 'inv_1', 'agent_1', $at, StopReason::EndOfTurn));

        self::assertSame([], $slice->turns[0]->projectionEvents());
    }

    #[Test]
    public function nonNormalStopReasonsRenderAsThreadProjection(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('Stop on max tokens')
            ->appendCue(new TokenDelta('cue_1', 1, 'act_1', 'inv_1', 'agent_1', $at, 'answer', Channel::Message))
            ->appendCue(new TokenStop('cue_2', 2, 'act_1', 'inv_1', 'agent_1', $at, StopReason::MaxTokens));

        $events = $slice->turns[0]->projectionEvents();

        self::assertCount(1, $events);
        self::assertSame(ConversationTurnEventKind::TokenStop, $events[0]->projection->kind);
        self::assertSame(ConversationTurnEventSeverity::Warning, $events[0]->projection->severity);
        self::assertSame('max-tokens', $events[0]->projection->stopReason);
    }
}
