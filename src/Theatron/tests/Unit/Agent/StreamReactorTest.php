<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Agent;

use DateTimeImmutable;
use Phalanx\Panoply\Cue\Activity\Cancelled as ActivityCancelled;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Effect\Authorized as EffectAuthorized;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
use Phalanx\Panoply\Cue\Effect\Paused as EffectPaused;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Theatron\Agent\StreamReactor;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Slice\ActivityStatus;
use Phalanx\Theatron\Template\Slice\ConversationTurnStatus;
use Phalanx\Theatron\Template\Slice\EffectStatus;
use Phalanx\Theatron\Template\Slice\LlmRequestEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamReactorTest extends TestCase
{
    #[Test]
    public function tokenDeltaUpdatesConversation(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new TokenDelta('cue_1', 1, 'act_zeus', null, null, $at, 'Hail from Olympus'),
        ], $store);

        $messages = $store->conversation->messages;
        self::assertCount(1, $messages);
        self::assertSame('Hail from Olympus', $messages[0]->text);
        self::assertSame('assistant', $messages[0]->role);
        self::assertSame('message', $messages[0]->channel);
        self::assertCount(1, $store->conversation->turns);
        self::assertSame('Hail from Olympus', $store->conversation->turns[0]->assistantText());
        self::assertSame('cue_1', $store->conversation->turns[0]->events[0]->id);
    }

    #[Test]
    public function tokenStopFinalizesMessage(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new TokenDelta('cue_1', 1, 'act_apollo', null, null, $at, 'Prophecy from Delphi'),
            new TokenStop('cue_2', 2, 'act_apollo', null, null, $at, StopReason::EndOfTurn),
        ], $store);

        $messages = $store->conversation->messages;
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->complete);
        self::assertFalse($store->conversation->isStreaming);
        self::assertSame(ConversationTurnStatus::Completed, $store->conversation->turns[0]->status);
    }

    #[Test]
    public function tokenStopWithErrorMarksTurnFailed(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new TokenDelta('cue_1', 1, 'act_apollo', null, null, $at, 'Partial prophecy'),
            new TokenStop('cue_2', 2, 'act_apollo', null, null, $at, StopReason::Error),
        ], $store);

        self::assertFalse($store->conversation->isStreaming);
        self::assertTrue($store->conversation->messages[0]->complete);
        self::assertSame(ConversationTurnStatus::Failed, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2'], self::conversationEventIds($store));
    }

    #[Test]
    public function tokenStopWithCancellationMarksTurnCancelled(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new TokenDelta('cue_1', 1, 'act_apollo', null, null, $at, 'Interrupted prophecy'),
            new TokenStop('cue_2', 2, 'act_apollo', null, null, $at, StopReason::Cancelled),
        ], $store);

        self::assertFalse($store->conversation->isStreaming);
        self::assertTrue($store->conversation->messages[0]->complete);
        self::assertSame(ConversationTurnStatus::Cancelled, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2'], self::conversationEventIds($store));
    }

    #[Test]
    public function thinkingChannelRoutesToThinkingBuffer(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new TokenDelta(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_zeus',
                invocationId: null,
                agentId: null,
                at: $at,
                text: 'Pondering the fate of Sparta...',
                channel: Channel::Thinking,
            ),
        ], $store);

        self::assertNotSame('', $store->conversation->thinkingBuffer);
        self::assertStringContainsString('Sparta', $store->conversation->thinkingBuffer);

        $messages = $store->conversation->messages;
        self::assertCount(1, $messages);
        self::assertSame('thinking', $messages[0]->channel);
        self::assertSame('Pondering the fate of Sparta...', $store->conversation->turns[0]->thinkingText());
        self::assertSame('', $store->conversation->turns[0]->assistantText());
    }

    #[Test]
    public function effectRequestedSetsAwaitingApproval(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_ares',
                invocationId: null,
                agentId: null,
                at: $at,
                effectId: 'eff_1',
                kind: Kind::FileWrite,
                summary: 'Write battle plan to /var/plans/thermopylae.txt',
                arguments: ['path' => '/var/plans/thermopylae.txt'],
                requiresApproval: true,
            ),
        ], $store);

        self::assertSame(ActivityStatus::AwaitingApproval, $store->activity->status);
        self::assertNotNull($store->activity->pendingEffect);
        self::assertSame('file.write', $store->activity->pendingEffect->kind);
        self::assertSame('act_ares', $store->activity->pendingEffect->activityId);
        self::assertSame('eff_1', $store->activity->pendingEffect->effectId);
        self::assertSame('medium', $store->activity->pendingEffect->hazard);
        self::assertCount(1, $store->effects->entries);
        self::assertSame(EffectStatus::Requested, $store->effects->entries[0]->status);
        self::assertSame(ConversationTurnStatus::AwaitingApproval, $store->conversation->turns[0]->status);
        self::assertSame('cue_1', $store->conversation->turns[0]->events[0]->id);
    }

    #[Test]
    public function effectRequestedWithoutApprovalDoesNotMutateActivity(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_hephaestus',
                invocationId: null,
                agentId: null,
                at: $at,
                effectId: 'eff_2',
                kind: Kind::WebFetch,
                summary: 'Fetch forge blueprints',
                arguments: [],
                requiresApproval: false,
            ),
        ], $store);

        self::assertSame(ActivityStatus::Idle, $store->activity->status);
        self::assertNull($store->activity->pendingEffect);
        self::assertCount(1, $store->effects->entries);
        self::assertSame('eff_2', $store->effects->entries[0]->effectId);
    }

    #[Test]
    public function effectExecutedResolvesEffect(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_poseidon',
                invocationId: null,
                agentId: null,
                at: $at,
                effectId: 'eff_3',
                kind: Kind::ShellExec,
                summary: 'Summon the seas',
                arguments: [],
                requiresApproval: true,
            ),
            new EffectExecuted('cue_2', 2, 'act_poseidon', null, null, $at, 'eff_3', 42),
        ], $store);

        self::assertSame(ActivityStatus::Running, $store->activity->status);
        self::assertNull($store->activity->pendingEffect);
        self::assertSame(EffectStatus::Executed, $store->effects->entries[0]->status);
        self::assertSame(42, $store->effects->entries[0]->durationMs);
        self::assertSame(ConversationTurnStatus::Running, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2'], self::conversationEventIds($store));
    }

    #[Test]
    public function effectDeniedResolvesEffect(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_hades',
                invocationId: null,
                agentId: null,
                at: $at,
                effectId: 'eff_4',
                kind: Kind::MemoryWrite,
                summary: 'Record souls crossing Styx',
                arguments: [],
                requiresApproval: true,
            ),
            new EffectDenied('cue_2', 2, 'act_hades', null, null, $at, 'eff_4', ['unauthorized']),
        ], $store);

        self::assertSame(ActivityStatus::Running, $store->activity->status);
        self::assertSame(EffectStatus::Denied, $store->effects->entries[0]->status);
        self::assertSame(['unauthorized'], $store->effects->entries[0]->reasonCodes);
        self::assertSame(ConversationTurnStatus::Running, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2'], self::conversationEventIds($store));
    }

    #[Test]
    public function effectPausedMarksEffectPausedWithReason(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_athena',
                invocationId: 'inv_1',
                agentId: 'agent_athena',
                at: $at,
                effectId: 'eff_5',
                kind: Kind::FileRead,
                summary: 'Read a strategy note',
                arguments: [],
                requiresApproval: true,
            ),
            new EffectPaused('cue_2', 2, 'act_athena', 'inv_1', 'agent_athena', $at, 'eff_5', 'Approval required'),
        ], $store);

        self::assertSame(EffectStatus::Paused, $store->effects->entries[0]->status);
        self::assertSame(['Approval required'], $store->effects->entries[0]->reasonCodes);
        self::assertSame(ConversationTurnStatus::AwaitingApproval, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2'], self::conversationEventIds($store));
    }

    #[Test]
    public function effectAuthorizedMarksEffectApprovedWithGrant(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_athena',
                invocationId: 'inv_1',
                agentId: 'agent_athena',
                at: $at,
                effectId: 'eff_5',
                kind: Kind::FileRead,
                summary: 'Read a strategy note',
                arguments: [],
                requiresApproval: true,
            ),
            new EffectAuthorized('cue_2', 2, 'act_athena', 'inv_1', 'agent_athena', $at, 'eff_5', 'grant_1'),
        ], $store);

        self::assertSame(EffectStatus::Approved, $store->effects->entries[0]->status);
        self::assertSame('grant_1', $store->effects->entries[0]->grantId);
        self::assertSame(ConversationTurnStatus::AwaitingApproval, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2'], self::conversationEventIds($store));
    }

    #[Test]
    public function effectFailedResolvesEffectAndRecordsFailure(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_hermes',
                invocationId: 'inv_1',
                agentId: 'agent_hermes',
                at: $at,
                effectId: 'eff_6',
                kind: Kind::WebFetch,
                summary: 'Fetch dispatch',
                arguments: [],
                requiresApproval: true,
            ),
            new EffectFailed(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_hermes',
                invocationId: 'inv_1',
                agentId: 'agent_hermes',
                at: $at,
                effectId: 'eff_6',
                reason: 'network unavailable',
                errorClass: \RuntimeException::class,
            ),
        ], $store);

        self::assertSame(ActivityStatus::Running, $store->activity->status);
        self::assertNull($store->activity->pendingEffect);
        self::assertSame(EffectStatus::Failed, $store->effects->entries[0]->status);
        self::assertSame(['network unavailable'], $store->effects->entries[0]->reasonCodes);
        self::assertSame(\RuntimeException::class, $store->effects->entries[0]->errorClass);
        self::assertSame(ConversationTurnStatus::Running, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2'], self::conversationEventIds($store));
    }

    #[Test]
    public function activityStartedSetsRunning(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new ActivityStarted('cue_1', 1, 'act_leonidas', null, 'agent_sparta', $at),
        ], $store);

        self::assertSame(ActivityStatus::Running, $store->activity->status);
    }

    #[Test]
    public function activityCompletedSetsCompleted(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new ActivityStarted('cue_1', 1, 'act_pericles', null, null, $at),
            new ActivityCompleted('cue_2', 2, 'act_pericles', null, null, $at),
        ], $store);

        self::assertSame(ActivityStatus::Completed, $store->activity->status);
        self::assertSame(ConversationTurnStatus::Completed, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2'], self::conversationEventIds($store));
    }

    #[Test]
    public function activityFailedSetsFailed(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new ActivityStarted('cue_1', 1, 'act_achilles', null, null, $at),
            new TokenDelta('cue_2', 2, 'act_achilles', null, null, $at, 'The line is breaking'),
            new ActivityFailed('cue_3', 3, 'act_achilles', null, null, $at, 'Struck by Paris'),
        ], $store);

        self::assertSame(ActivityStatus::Failed, $store->activity->status);
        self::assertFalse($store->conversation->isStreaming);
        self::assertTrue($store->conversation->messages[0]->complete);
        self::assertSame(ConversationTurnStatus::Failed, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2', 'cue_3'], self::conversationEventIds($store));
    }

    #[Test]
    public function activityCancelledSetsCancelled(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new ActivityStarted('cue_1', 1, 'act_odysseus', null, null, $at),
            new TokenDelta('cue_2', 2, 'act_odysseus', null, null, $at, 'The voyage pauses'),
            new ActivityCancelled('cue_3', 3, 'act_odysseus', null, null, $at, 'Sirens called'),
        ], $store);

        self::assertSame(ActivityStatus::Cancelled, $store->activity->status);
        self::assertFalse($store->conversation->isStreaming);
        self::assertTrue($store->conversation->messages[0]->complete);
        self::assertSame(ConversationTurnStatus::Cancelled, $store->conversation->turns[0]->status);
        self::assertSame(['cue_1', 'cue_2', 'cue_3'], self::conversationEventIds($store));
    }

    #[Test]
    public function usageDeltaUpdatesTokenCounters(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new UsageDelta('cue_1', 1, 'act_plato', null, null, $at, 150, 300),
        ], $store);

        self::assertSame(150, $store->activity->inputTokens);
        self::assertSame(300, $store->activity->outputTokens);
        self::assertSame(450, $store->activity->totalTokens);
        self::assertSame(['cue_1'], self::conversationEventIds($store));
    }

    #[Test]
    public function finalUsageReplacesTokenCounters(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new UsageDelta('cue_1', 1, 'act_plato', null, null, $at, 25, 75),
            new FinalUsage('cue_2', 2, 'act_plato', null, null, $at, 150, 300),
        ], $store);

        self::assertSame(150, $store->activity->inputTokens);
        self::assertSame(300, $store->activity->outputTokens);
        self::assertSame(450, $store->activity->totalTokens);
        self::assertSame(['cue_1', 'cue_2'], self::conversationEventIds($store));
    }

    #[Test]
    public function finalUsageRefreshesMatchingRequestTokenCount(): void
    {
        $store = new AppStore();
        $store->requests = $store->requests
            ->append(new LlmRequestEntry('req-1', 'POST', '/first', invocationId: 'inv-1'))
            ->append(new LlmRequestEntry('req-2', 'POST', '/second', invocationId: 'inv-2'))
            ->completeById('req-2', 200, 25.0, 0, '{}')
            ->focusUp();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new FinalUsage('cue_1', 1, 'act_plato', 'inv-2', null, $at, 150, 300),
        ], $store);

        self::assertSame(0, $store->requests->focusedIndex);
        self::assertNull($store->requests->entries[0]->tokenCount);
        self::assertSame(450, $store->requests->entries[1]->tokenCount);
    }

    #[Test]
    public function finalUsageWithoutInvocationDoesNotMutateFocusedRequest(): void
    {
        $store = new AppStore();
        $store->requests = $store->requests
            ->append(new LlmRequestEntry('req-1', 'POST', '/first'))
            ->completeById('req-1', 200, 25.0, 0, '{}');
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new FinalUsage('cue_1', 1, 'act_plato', null, null, $at, 150, 300),
        ], $store);

        self::assertSame(0, $store->requests->focused()?->tokenCount);
    }

    #[Test]
    public function fullConversationSequence(): void
    {
        $store = new AppStore();
        $at = new DateTimeImmutable();

        StreamReactor::consume([
            new ActivityStarted('cue_1', 1, 'act_themistocles', null, 'agent_athens', $at),
            new TokenDelta('cue_2', 2, 'act_themistocles', null, 'agent_athens', $at, 'The fleet at'),
            new TokenDelta('cue_3', 3, 'act_themistocles', null, 'agent_athens', $at, ' Salamis'),
            new TokenDelta('cue_4', 4, 'act_themistocles', null, 'agent_athens', $at, ' holds the line.'),
            new TokenStop('cue_5', 5, 'act_themistocles', null, 'agent_athens', $at, StopReason::EndOfTurn),
            new ActivityCompleted('cue_6', 6, 'act_themistocles', null, 'agent_athens', $at),
        ], $store);

        self::assertSame(ActivityStatus::Completed, $store->activity->status);
        self::assertFalse($store->conversation->isStreaming);

        $messages = $store->conversation->messages;
        self::assertCount(1, $messages);
        self::assertSame('The fleet at Salamis holds the line.', $messages[0]->text);
        self::assertTrue($messages[0]->complete);
        self::assertSame('message', $messages[0]->channel);
        self::assertCount(1, $store->conversation->turns);
        self::assertSame('The fleet at Salamis holds the line.', $store->conversation->turns[0]->assistantText());
        self::assertSame(ConversationTurnStatus::Completed, $store->conversation->turns[0]->status);
        self::assertCount(6, $store->conversation->turns[0]->events);
    }

    /**
     * @return list<string>
     */
    private static function conversationEventIds(AppStore $store): array
    {
        $ids = [];

        foreach ($store->conversation->turns[0]->events ?? [] as $event) {
            $ids[] = $event->id;
        }

        return $ids;
    }
}
