<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Slice;

use DateTimeImmutable;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Cancelled as ActivityCancelled;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Artifact\Delta as ArtifactDelta;
use Phalanx\Panoply\Cue\Artifact\Drafting as ArtifactDrafting;
use Phalanx\Panoply\Cue\Artifact\Finalized as ArtifactFinalized;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta as EffectArgumentsDelta;
use Phalanx\Panoply\Cue\Effect\Authorized as EffectAuthorized;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
use Phalanx\Panoply\Cue\Effect\Paused as EffectPaused;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Invocation\Cancelled as InvocationCancelled;
use Phalanx\Panoply\Cue\Invocation\Completed as InvocationCompleted;
use Phalanx\Panoply\Cue\Invocation\Failed as InvocationFailed;
use Phalanx\Panoply\Cue\Invocation\Started as InvocationStarted;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\StructuredDelta;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Provider\RateLimited as ProviderRateLimited;
use Phalanx\Panoply\Cue\Provider\Resolved as ProviderResolved;
use Phalanx\Panoply\Cue\Provider\Retrying as ProviderRetrying;
use Phalanx\Panoply\Cue\Runtime\ClientConnected as RuntimeClientConnected;
use Phalanx\Panoply\Cue\Runtime\ClientDisconnected as RuntimeClientDisconnected;
use Phalanx\Panoply\Cue\Runtime\Error as RuntimeError;
use Phalanx\Panoply\Cue\Runtime\Notice as RuntimeNotice;
use Phalanx\Panoply\Cue\Runtime\Warning as RuntimeWarning;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Theatron\Template\Slice\ConversationSlice;
use Phalanx\Theatron\Template\Slice\ConversationTurnEvent;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventKind;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventSeverity;
use Phalanx\Theatron\Template\Slice\ConversationTurnStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationSliceTest extends TestCase
{
    /**
     * @return iterable<string, array{Cue, ConversationTurnEventKind, ConversationTurnEventSeverity, bool}>
     */
    public static function projectionCueProvider(): iterable
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $base = ['cue_1', 1, 'act_1', 'inv_1', 'agent_1', $at];

        yield 'token stop normal' => [
            new TokenStop(...$base, reason: StopReason::EndOfTurn),
            ConversationTurnEventKind::TokenStop,
            ConversationTurnEventSeverity::Muted,
            false,
        ];
        yield 'token stop tool-use' => [
            new TokenStop(...$base, reason: StopReason::ToolUse),
            ConversationTurnEventKind::TokenStop,
            ConversationTurnEventSeverity::Info,
            false,
        ];
        yield 'token stop max-tokens' => [
            new TokenStop(...$base, reason: StopReason::MaxTokens),
            ConversationTurnEventKind::TokenStop,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'structured delta' => [
            new StructuredDelta(...$base, jsonDelta: '{"path":"value"}', path: '$.path'),
            ConversationTurnEventKind::StructuredDelta,
            ConversationTurnEventSeverity::Muted,
            false,
        ];
        yield 'effect requested' => [
            new EffectRequested(...$base, effectId: 'eff_1', kind: EffectKind::FileRead, summary: 'Read file', requiresApproval: true),
            ConversationTurnEventKind::EffectRequested,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'effect arguments delta' => [
            new EffectArgumentsDelta(...$base, effectId: 'eff_1', jsonDelta: '{"path"'),
            ConversationTurnEventKind::EffectArgumentsDelta,
            ConversationTurnEventSeverity::Muted,
            true,
        ];
        yield 'effect authorized' => [
            new EffectAuthorized(...$base, effectId: 'eff_1', grantId: 'grant_1'),
            ConversationTurnEventKind::EffectAuthorized,
            ConversationTurnEventSeverity::Success,
            true,
        ];
        yield 'effect paused' => [
            new EffectPaused(...$base, effectId: 'eff_1', reason: 'approval'),
            ConversationTurnEventKind::EffectPaused,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'effect denied' => [
            new EffectDenied(...$base, effectId: 'eff_1', reasonCodes: ['policy']),
            ConversationTurnEventKind::EffectDenied,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'effect executed' => [
            new EffectExecuted(...$base, effectId: 'eff_1', durationMs: 42, resultDigest: 'ok'),
            ConversationTurnEventKind::EffectExecuted,
            ConversationTurnEventSeverity::Success,
            true,
        ];
        yield 'effect failed' => [
            new EffectFailed(...$base, effectId: 'eff_1', reason: 'failed', errorClass: 'RuntimeException'),
            ConversationTurnEventKind::EffectFailed,
            ConversationTurnEventSeverity::Error,
            true,
        ];
        yield 'invocation started' => [
            new InvocationStarted(...$base),
            ConversationTurnEventKind::InvocationStarted,
            ConversationTurnEventSeverity::Muted,
            false,
        ];
        yield 'invocation completed normal' => [
            new InvocationCompleted(...$base, stopReason: StopReason::EndOfTurn),
            ConversationTurnEventKind::InvocationCompleted,
            ConversationTurnEventSeverity::Muted,
            false,
        ];
        yield 'invocation completed max-tokens' => [
            new InvocationCompleted(...$base, stopReason: StopReason::MaxTokens),
            ConversationTurnEventKind::InvocationCompleted,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'invocation failed' => [
            new InvocationFailed(...$base, reason: 'failed', errorClass: 'ProviderException'),
            ConversationTurnEventKind::InvocationFailed,
            ConversationTurnEventSeverity::Error,
            true,
        ];
        yield 'invocation cancelled' => [
            new InvocationCancelled(...$base, reason: 'cancelled'),
            ConversationTurnEventKind::InvocationCancelled,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'usage delta' => [
            new UsageDelta(...$base, inputTokens: 1, outputTokens: 2, cacheReadTokens: 0, cacheWriteTokens: 0),
            ConversationTurnEventKind::UsageDelta,
            ConversationTurnEventSeverity::Muted,
            false,
        ];
        yield 'final usage' => [
            new FinalUsage(...$base, inputTokens: 1, outputTokens: 2, cacheReadTokens: 0, cacheWriteTokens: 0, costUsd: 0.01),
            ConversationTurnEventKind::UsageFinal,
            ConversationTurnEventSeverity::Muted,
            true,
        ];
        yield 'activity started' => [
            new ActivityStarted(...$base),
            ConversationTurnEventKind::ActivityStarted,
            ConversationTurnEventSeverity::Muted,
            false,
        ];
        yield 'activity completed' => [
            new ActivityCompleted(...$base),
            ConversationTurnEventKind::ActivityCompleted,
            ConversationTurnEventSeverity::Success,
            false,
        ];
        yield 'activity failed' => [
            new ActivityFailed(...$base, reason: 'failed', errorClass: 'ActivityException'),
            ConversationTurnEventKind::ActivityFailed,
            ConversationTurnEventSeverity::Error,
            true,
        ];
        yield 'activity cancelled' => [
            new ActivityCancelled(...$base, reason: 'cancelled'),
            ConversationTurnEventKind::ActivityCancelled,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'artifact drafting' => [
            new ArtifactDrafting(...$base, artifactId: 'art_1', kind: ArtifactKind::PatchDraft, title: 'Patch'),
            ConversationTurnEventKind::ArtifactDrafting,
            ConversationTurnEventSeverity::Info,
            true,
        ];
        yield 'artifact delta' => [
            new ArtifactDelta(...$base, artifactId: 'art_1', contentDelta: 'diff', path: 'patch.diff'),
            ConversationTurnEventKind::ArtifactDelta,
            ConversationTurnEventSeverity::Muted,
            false,
        ];
        yield 'artifact finalized' => [
            new ArtifactFinalized(...$base, artifactId: 'art_1', contentHash: 'sha256:abc'),
            ConversationTurnEventKind::ArtifactFinalized,
            ConversationTurnEventSeverity::Success,
            true,
        ];
        yield 'provider resolved' => [
            new ProviderResolved(...$base, provider: 'openai', model: 'gpt-5.1', reasonCode: 'configured'),
            ConversationTurnEventKind::ProviderResolved,
            ConversationTurnEventSeverity::Muted,
            false,
        ];
        yield 'provider retrying' => [
            new ProviderRetrying(...$base, provider: 'openai', attempt: 2, maxAttempts: 3, backoffMs: 100),
            ConversationTurnEventKind::ProviderRetrying,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'provider rate limited' => [
            new ProviderRateLimited(...$base, provider: 'openai', model: 'gpt-5.1', retryAfterSeconds: 30),
            ConversationTurnEventKind::ProviderRateLimited,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'runtime client connected' => [
            new RuntimeClientConnected(...$base, clientId: 'client_1', clientKind: 'cli'),
            ConversationTurnEventKind::RuntimeClientConnected,
            ConversationTurnEventSeverity::Muted,
            false,
        ];
        yield 'runtime client disconnected' => [
            new RuntimeClientDisconnected(...$base, clientId: 'client_1', reason: 'closed'),
            ConversationTurnEventKind::RuntimeClientDisconnected,
            ConversationTurnEventSeverity::Warning,
            false,
        ];
        yield 'runtime error' => [
            new RuntimeError(...$base, message: 'failed', code: 'runtime.failed', errorClass: 'RuntimeException'),
            ConversationTurnEventKind::RuntimeError,
            ConversationTurnEventSeverity::Error,
            true,
        ];
        yield 'runtime warning' => [
            new RuntimeWarning(...$base, message: 'warn', code: 'runtime.warn'),
            ConversationTurnEventKind::RuntimeWarning,
            ConversationTurnEventSeverity::Warning,
            true,
        ];
        yield 'runtime notice' => [
            new RuntimeNotice(...$base, message: 'notice', code: 'runtime.notice'),
            ConversationTurnEventKind::RuntimeNotice,
            ConversationTurnEventSeverity::Info,
            true,
        ];
    }

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
                kind: EffectKind::FileRead,
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
                kind: EffectKind::FileRead,
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
        self::assertNull($events[0]->projection->toolName);
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
    public function effectLogsExposeAthenaResolutionProjectionForRuntimeRendering(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('Use runtime tools')
            ->appendEffectLog(new EffectLogRecord(
                id: 'effect_log_1',
                invocationId: 'inv_1',
                kind: 'tool_call',
                toolName: 'read_file',
                argsHash: 'sha256:abc',
                resolution: Resolution::LocalTool,
                outcome: 'ok',
                at: $at,
            ))
            ->appendEffectLog(new EffectLogRecord(
                id: 'effect_log_2',
                invocationId: 'inv_1',
                kind: 'tool_call',
                toolName: 'search_docs',
                argsHash: 'sha256:def',
                resolution: Resolution::McpTool,
                outcome: 'failed',
                at: $at,
            ));

        $events = $slice->turns[0]->projectionEvents();

        self::assertCount(2, $events);
        self::assertSame(ConversationTurnEventKind::EffectLogged, $events[0]->projection->kind);
        self::assertSame(ConversationTurnEventSeverity::Success, $events[0]->projection->severity);
        self::assertSame(Resolution::LocalTool, $events[0]->projection->resolution);
        self::assertSame('read_file', $events[0]->projection->toolName);
        self::assertSame('sha256:abc', $events[0]->projection->argsHash);
        self::assertSame('ok', $events[0]->projection->outcome);
        self::assertSame(ConversationTurnEventSeverity::Error, $events[1]->projection->severity);
        self::assertSame(Resolution::McpTool, $events[1]->projection->resolution);
        self::assertSame('search_docs', $events[1]->projection->toolName);
        self::assertTrue($events[0]->projection->rendersInThread());
        self::assertTrue($events[1]->projection->rendersInThread());
    }

    #[Test]
    public function effectLogSeverityUsesKnownOutcomesAndLeavesUnknownsInformational(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('Classify tool outcomes')
            ->appendEffectLog(new EffectLogRecord(
                id: 'effect_log_1',
                invocationId: 'inv_1',
                kind: 'tool_call',
                toolName: 'read_file',
                argsHash: 'sha256:abc',
                resolution: Resolution::LocalTool,
                outcome: 'exit_0',
                at: $at,
            ))
            ->appendEffectLog(new EffectLogRecord(
                id: 'effect_log_2',
                invocationId: 'inv_1',
                kind: 'tool_call',
                toolName: 'request_approval',
                argsHash: 'sha256:def',
                resolution: Resolution::BuiltIn,
                outcome: 'waiting-for-approval',
                at: $at,
            ))
            ->appendEffectLog(new EffectLogRecord(
                id: 'effect_log_3',
                invocationId: 'inv_1',
                kind: 'tool_call',
                toolName: 'ambiguous',
                argsHash: 'sha256:ghi',
                resolution: Resolution::SubAgent,
                outcome: 'not_error',
                at: $at,
            ));

        $events = $slice->turns[0]->projectionEvents();

        self::assertSame(ConversationTurnEventSeverity::Success, $events[0]->projection->severity);
        self::assertSame(ConversationTurnEventSeverity::Warning, $events[1]->projection->severity);
        self::assertSame(ConversationTurnEventSeverity::Info, $events[2]->projection->severity);
    }

    #[Test]
    public function grantsExposeApprovalScopeProjectionForRuntimeRendering(): void
    {
        $expiresAt = new DateTimeImmutable('2026-05-24T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('Show grants')
            ->appendGrant(new Grant(
                id: 'grant_1',
                subject: 'agent_1',
                allowedEffects: [EffectKind::FileRead, EffectKind::CodeSearch],
                scope: 'session',
                hazardCeiling: Hazard::Medium,
                expiresAt: $expiresAt,
                conditions: ['cwd' => '/workspace'],
            ), new DateTimeImmutable('2026-05-23T21:00:00Z'));

        $events = $slice->turns[0]->projectionEvents();

        self::assertCount(1, $events);
        self::assertSame(ConversationTurnEventKind::GrantAvailable, $events[0]->projection->kind);
        self::assertSame(ConversationTurnEventSeverity::Info, $events[0]->projection->severity);
        self::assertSame('grant_1', $events[0]->projection->grantId);
        self::assertSame('agent_1', $events[0]->projection->subject);
        self::assertSame('session', $events[0]->projection->scope);
        self::assertSame('medium', $events[0]->projection->hazardCeiling);
        self::assertSame($expiresAt, $events[0]->projection->expiresAt);
        self::assertSame([EffectKind::FileRead, EffectKind::CodeSearch], $events[0]->projection->allowedEffects);
        self::assertSame(['cwd' => '/workspace'], $events[0]->projection->conditions);
        self::assertTrue($events[0]->projection->rendersInThread());
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

        self::assertCount(1, $slice->turns[0]->projectionEvents());
        self::assertSame([], $slice->turns[0]->threadProjectionEvents());
    }

    #[Test]
    public function nonNormalStopReasonsRenderAsThreadProjection(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('Stop on max tokens')
            ->appendCue(new TokenDelta('cue_1', 1, 'act_1', 'inv_1', 'agent_1', $at, 'answer', Channel::Message))
            ->appendCue(new TokenStop('cue_2', 2, 'act_1', 'inv_1', 'agent_1', $at, StopReason::MaxTokens));

        $events = $slice->turns[0]->threadProjectionEvents();

        self::assertCount(1, $events);
        self::assertSame(ConversationTurnEventKind::TokenStop, $events[0]->projection->kind);
        self::assertSame(ConversationTurnEventSeverity::Warning, $events[0]->projection->severity);
        self::assertSame(StopReason::MaxTokens, $events[0]->projection->stopReason);
    }

    #[Test]
    public function toolUseStopKeepsTheCurrentTurnOpenForTheFinalAnswer(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $slice = (new ConversationSlice())
            ->addUserMessage('Use a tool, then answer')
            ->appendCue(new TokenDelta('cue_1', 1, 'act_1', 'inv_1', 'agent_1', $at, 'Thinking', Channel::Thinking))
            ->appendCue(new TokenStop('cue_2', 2, 'act_1', 'inv_1', 'agent_1', $at, StopReason::ToolUse))
            ->appendCue(new EffectExecuted(
                id: 'cue_3',
                sequence: 3,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                durationMs: 12,
                resultDigest: 'ok',
            ))
            ->appendCue(new TokenDelta('cue_4', 4, 'act_1', 'inv_1', 'agent_1', $at, 'Final answer', Channel::Message));

        self::assertCount(1, $slice->turns);
        self::assertSame('Use a tool, then answer', $slice->turns[0]->userText);
        self::assertSame('Final answer', $slice->turns[0]->assistantText());
        self::assertSame(ConversationTurnStatus::Running, $slice->turns[0]->status);
        self::assertFalse($slice->turns[0]->projectionEvents()[0]->projection->rendersInThread());
    }

    /**
     * @param bool $rendersInThread
     */
    #[DataProvider('projectionCueProvider')]
    #[Test]
    public function panoplyCuesExposeSpecificProjectionContracts(
        Cue $cue,
        ConversationTurnEventKind $kind,
        ConversationTurnEventSeverity $severity,
        bool $rendersInThread,
    ): void {
        $projection = ConversationTurnEvent::fromCue($cue)->projection;

        self::assertSame($kind, $projection->kind);
        self::assertSame($severity, $projection->severity);
        self::assertSame($rendersInThread, $projection->rendersInThread());
    }
}
