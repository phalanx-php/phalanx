<?php

declare(strict_types=1);

namespace Phalanx\Agora\Tests\Unit\Theatron;

use DateTimeImmutable;
use Phalanx\Agora\Harness\EventSource;
use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionSet;
use Phalanx\Agora\Harness\Replay\ReplaySession;
use Phalanx\Agora\Theatron\TheatronReplayHydrator;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Cancelled as ActivityCancelled;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
use Phalanx\Panoply\Cue\Effect\Paused as EffectPaused;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Invocation\Failed as InvocationFailed;
use Phalanx\Panoply\Cue\Invocation\Started as InvocationStarted;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Screen\ChatScreen;
use Phalanx\Theatron\Template\Slice\ActivityStatus;
use Phalanx\Theatron\Template\Slice\ConversationTurnEventKind;
use Phalanx\Theatron\Template\Slice\ConversationTurnStatus;
use Phalanx\Theatron\Template\Slice\EffectStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TheatronReplayHydratorTest extends TestCase
{
    private const string SESSION_ID = 'session.ui';
    private const string TURN_ID = 'turn.ui';

    #[Test]
    public function itHydratesConversationActivityEffectsAndWorkspaceFromPersistedReplay(): void
    {
        $events = self::events();
        $store = new AppStore();
        (new TheatronReplayHydrator())->hydrate(
            store: $store,
            session: new ReplaySession(
                sessionId: self::SESSION_ID,
                projections: ProjectionSet::empty(self::SESSION_ID),
                events: $events,
                checkpointSequence: 7,
            ),
        );

        $turn = $store->conversation->turns[0];
        $projectionKinds = array_map(
            static fn($event) => $event->projection->kind,
            $turn->projectionEvents(),
        );

        self::assertSame('and what makes you so sure?', $turn->userText);
        self::assertSame('thinking through it', $turn->thinkingText());
        self::assertSame('Final answer.', $turn->assistantText());
        self::assertSame(ConversationTurnStatus::Completed, $turn->status);
        self::assertContains(ConversationTurnEventKind::EffectRequested, $projectionKinds);
        self::assertContains(ConversationTurnEventKind::EffectExecuted, $projectionKinds);
        self::assertContains(ConversationTurnEventKind::UsageFinal, $projectionKinds);

        self::assertSame(ActivityStatus::Completed, $store->activity->status);
        self::assertSame(5, $store->activity->inputTokens);
        self::assertSame(7, $store->activity->outputTokens);
        self::assertSame(12, $store->activity->totalTokens);

        self::assertCount(1, $store->effects->entries);
        self::assertSame('effect.read', $store->effects->entries[0]->effectId);
        self::assertSame(EffectStatus::Executed, $store->effects->entries[0]->status);

        self::assertSame(3, $store->workspaceView->chatScrollOffset);
        self::assertSame(self::TURN_ID, $store->workspaceView->selectedTurnId);
        self::assertSame(self::TURN_ID, $store->workspaceView->expandedTurnId);
        self::assertSame(InputMode::Insert, $store->workspaceView->inputModeFor(ChatScreen::class)?->mode);
    }

    #[Test]
    public function itCoercesInvalidWorkspaceRestoreFields(): void
    {
        $events = [
            self::userEvent(1),
            HarnessEvent::marker(
                id: 'event.restore.invalid',
                sessionId: self::SESSION_ID,
                sequence: 2,
                cueType: 'agora.workspace.restore',
                source: EventSource::Agora,
                occurredAt: self::at(2),
                payload: [
                    'scroll_offset' => -4,
                    'selected_turn_id' => 123,
                    'expanded_block' => [],
                    'input_mode' => 'invalid',
                ],
            ),
        ];
        $store = new AppStore();

        (new TheatronReplayHydrator())->hydrate(
            store: $store,
            session: new ReplaySession(self::SESSION_ID, self::project($events), $events, checkpointSequence: 0),
        );

        self::assertSame(0, $store->workspaceView->chatScrollOffset);
        self::assertNull($store->workspaceView->selectedTurnId);
        self::assertNull($store->workspaceView->expandedTurnId);
        self::assertNull($store->workspaceView->inputModeFor(ChatScreen::class));
    }

    #[Test]
    public function itHydratesFromTheFullEventReplayInsteadOfAStaleCheckpointSnapshot(): void
    {
        $events = self::events();
        $staleEvents = [
            self::userEvent(1),
            self::workspaceRestore(2, 99, 'turn.stale', InputMode::Normal),
            self::eventFromCue(new FinalUsage(
                id: 'cue.usage.stale',
                sequence: 3,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(3),
                inputTokens: 99,
                outputTokens: 1,
            )),
        ];
        $store = new AppStore();

        (new TheatronReplayHydrator())->hydrate(
            store: $store,
            session: new ReplaySession(self::SESSION_ID, self::project($staleEvents), $events, checkpointSequence: 3),
        );

        self::assertSame(3, $store->workspaceView->chatScrollOffset);
        self::assertSame(self::TURN_ID, $store->workspaceView->selectedTurnId);
        self::assertSame(InputMode::Insert, $store->workspaceView->inputModeFor(ChatScreen::class)?->mode);
        self::assertSame(12, $store->activity->totalTokens);
    }

    #[Test]
    public function itKeepsRepeatedEffectIdsSeparateAcrossTurns(): void
    {
        $events = [
            self::userEvent(1, 'turn.one', 'first'),
            self::eventFromCue(self::effectRequested(2, 'turn.one', 'Read first file', ['path' => 'one.txt']), 'turn.one'),
            self::eventFromCue(new EffectExecuted(
                id: 'cue.effect.executed.one',
                sequence: 3,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(3),
                effectId: 'effect.read',
                durationMs: 10,
                resultDigest: 'first',
            ), 'turn.one'),
            self::userEvent(4, 'turn.two', 'second'),
            self::eventFromCue(self::effectRequested(5, 'turn.two', 'Read second file', ['path' => 'two.txt']), 'turn.two'),
            self::eventFromCue(new EffectFailed(
                id: 'cue.effect.failed.two',
                sequence: 6,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(6),
                effectId: 'effect.read',
                reason: 'missing file',
                errorClass: 'RuntimeException',
            ), 'turn.two'),
        ];
        $store = new AppStore();

        (new TheatronReplayHydrator())->hydrate(
            store: $store,
            session: new ReplaySession(self::SESSION_ID, self::project($events), $events, checkpointSequence: 0),
        );

        self::assertCount(2, $store->effects->entries);
        self::assertSame('Read first file', $store->effects->entries[0]->summary);
        self::assertSame(EffectStatus::Executed, $store->effects->entries[0]->status);
        self::assertSame(['path' => 'one.txt'], $store->effects->entries[0]->arguments);
        self::assertSame('Read second file', $store->effects->entries[1]->summary);
        self::assertSame(EffectStatus::Failed, $store->effects->entries[1]->status);
        self::assertSame(['path' => 'two.txt'], $store->effects->entries[1]->arguments);
        self::assertSame('RuntimeException', $store->effects->entries[1]->errorClass);
    }

    #[Test]
    public function itHydratesReplayStatusTransitionsForFailureCancellationAndToolUse(): void
    {
        $events = [
            self::userEvent(1, 'turn.error', 'error'),
            self::tokenStop(2, 'turn.error', StopReason::Error),
            self::userEvent(3, 'turn.cancelled', 'cancelled'),
            self::tokenStop(4, 'turn.cancelled', StopReason::Cancelled),
            self::userEvent(5, 'turn.tool', 'tool'),
            self::tokenStop(6, 'turn.tool', StopReason::ToolUse),
            self::userEvent(7, 'turn.invocation.failed', 'invocation failed'),
            self::eventFromCue(new InvocationFailed(
                id: 'cue.invocation.failed',
                sequence: 8,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(8),
                reason: 'provider error',
                errorClass: 'ProviderException',
            ), 'turn.invocation.failed'),
            self::userEvent(9, 'turn.activity.cancelled', 'activity cancelled'),
            self::eventFromCue(new ActivityCancelled(
                id: 'cue.activity.cancelled',
                sequence: 10,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(10),
                reason: 'user aborted',
            ), 'turn.activity.cancelled'),
        ];
        $store = new AppStore();

        (new TheatronReplayHydrator())->hydrate(
            store: $store,
            session: new ReplaySession(self::SESSION_ID, self::project($events), $events, checkpointSequence: 0),
        );

        self::assertSame(ConversationTurnStatus::Failed, $store->conversation->turns[0]->status);
        self::assertSame(ConversationTurnStatus::Cancelled, $store->conversation->turns[1]->status);
        self::assertSame(ConversationTurnStatus::Running, $store->conversation->turns[2]->status);
        self::assertSame(ConversationTurnStatus::Failed, $store->conversation->turns[3]->status);
        self::assertSame(ConversationTurnStatus::Cancelled, $store->conversation->turns[4]->status);
        self::assertSame(ActivityStatus::Failed, $store->activity->status);
    }

    #[Test]
    public function itHydratesMidStreamReplayState(): void
    {
        $events = [
            self::userEvent(1),
            self::eventFromCue(new InvocationStarted(
                id: 'cue.invocation.started',
                sequence: 2,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(2),
            )),
            self::token(3, 'still ', Channel::Thinking),
            self::token(4, 'answering', Channel::Message),
            self::eventFromCue(new UsageDelta(
                id: 'cue.usage.delta',
                sequence: 5,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(5),
                inputTokens: 2,
                outputTokens: 3,
            )),
        ];
        $store = new AppStore();

        (new TheatronReplayHydrator())->hydrate(
            store: $store,
            session: new ReplaySession(self::SESSION_ID, self::project($events), $events, checkpointSequence: 0),
        );

        self::assertTrue($store->conversation->isStreaming);
        self::assertSame(ActivityStatus::Running, $store->activity->status);
        self::assertSame(5, $store->activity->totalTokens);
        self::assertSame('still ', $store->conversation->turns[0]->thinkingText());
        self::assertSame('answering', $store->conversation->turns[0]->assistantText());
    }

    #[Test]
    public function itHydratesDeniedPausedFailedAndRuntimeEvents(): void
    {
        $events = [
            self::userEvent(1),
            self::eventFromCue(self::effectRequested(2, self::TURN_ID, 'Needs approval', ['path' => 'one.txt']), self::TURN_ID),
            self::eventFromCue(new EffectPaused(
                id: 'cue.effect.paused',
                sequence: 3,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(3),
                effectId: 'effect.read',
                reason: 'policy',
            )),
            self::eventFromCue(new EffectDenied(
                id: 'cue.effect.denied',
                sequence: 4,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(4),
                effectId: 'effect.read',
                reasonCodes: ['policy'],
            )),
            self::eventFromCue(new ActivityFailed(
                id: 'cue.activity.failed',
                sequence: 5,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at(5),
                reason: 'runtime crashed',
                errorClass: 'RuntimeException',
            )),
            HarnessEvent::marker(
                id: 'event.runtime.error',
                sessionId: self::SESSION_ID,
                sequence: 6,
                cueType: 'cue.runtime.error',
                source: EventSource::Panoply,
                occurredAt: self::at(6),
                payload: [
                    'payload' => [
                        'message' => 'runtime crashed',
                        'code' => 'runtime.error',
                        'error_class' => 'RuntimeException',
                    ],
                ],
                turnId: self::TURN_ID,
            ),
        ];
        $store = new AppStore();

        (new TheatronReplayHydrator())->hydrate(
            store: $store,
            session: new ReplaySession(self::SESSION_ID, self::project($events), $events, checkpointSequence: 0),
        );

        $projectionKinds = array_map(
            static fn($event) => $event->projection->kind,
            $store->conversation->turns[0]->projectionEvents(),
        );

        self::assertContains(ConversationTurnEventKind::EffectPaused, $projectionKinds);
        self::assertContains(ConversationTurnEventKind::EffectDenied, $projectionKinds);
        self::assertContains(ConversationTurnEventKind::ActivityFailed, $projectionKinds);
        self::assertContains(ConversationTurnEventKind::RuntimeError, $projectionKinds);
        self::assertSame(ConversationTurnStatus::Failed, $store->conversation->turns[0]->status);
        self::assertSame(ActivityStatus::Failed, $store->activity->status);
        self::assertSame(EffectStatus::Denied, $store->effects->entries[0]->status);
        self::assertSame(['policy'], $store->effects->entries[0]->reasonCodes);
    }

    /** @return list<HarnessEvent> */
    private static function events(): array
    {
        return [
            self::userEvent(1),
            self::token(2, 'thinking ', Channel::Thinking),
            self::token(3, 'through it', Channel::Reasoning),
            self::token(4, 'Final ', Channel::Message),
            HarnessEvent::fromCue(
                cue: new EffectRequested(
                    id: 'cue.effect.requested',
                    sequence: 5,
                    activityId: 'activity.ui',
                    invocationId: 'invocation.ui',
                    agentId: 'agent.ui',
                    at: self::at(5),
                    effectId: 'effect.read',
                    kind: EffectKind::FileRead,
                    summary: 'Read project file',
                    arguments: ['path' => 'composer.json'],
                    requiresApproval: true,
                ),
                sessionId: self::SESSION_ID,
                sequence: 5,
                turnId: self::TURN_ID,
                id: 'event.5',
            ),
            HarnessEvent::fromCue(
                cue: new EffectExecuted(
                    id: 'cue.effect.executed',
                    sequence: 6,
                    activityId: 'activity.ui',
                    invocationId: 'invocation.ui',
                    agentId: 'agent.ui',
                    at: self::at(6),
                    effectId: 'effect.read',
                    durationMs: 42,
                    resultDigest: 'ok',
                ),
                sessionId: self::SESSION_ID,
                sequence: 6,
                turnId: self::TURN_ID,
                id: 'event.6',
            ),
            HarnessEvent::fromCue(
                cue: new FinalUsage(
                    id: 'cue.usage.final',
                    sequence: 7,
                    activityId: 'activity.ui',
                    invocationId: 'invocation.ui',
                    agentId: 'agent.ui',
                    at: self::at(7),
                    inputTokens: 5,
                    outputTokens: 7,
                ),
                sessionId: self::SESSION_ID,
                sequence: 7,
                turnId: self::TURN_ID,
                id: 'event.7',
            ),
            self::token(8, 'answer.', Channel::Message),
            HarnessEvent::fromCue(
                cue: new TokenStop(
                    id: 'cue.stop',
                    sequence: 9,
                    activityId: 'activity.ui',
                    invocationId: 'invocation.ui',
                    agentId: 'agent.ui',
                    at: self::at(9),
                    reason: StopReason::EndOfTurn,
                ),
                sessionId: self::SESSION_ID,
                sequence: 9,
                turnId: self::TURN_ID,
                id: 'event.9',
            ),
            HarnessEvent::marker(
                id: 'event.workspace.restore',
                sessionId: self::SESSION_ID,
                sequence: 10,
                cueType: 'agora.workspace.restore',
                source: EventSource::Agora,
                occurredAt: self::at(10),
                payload: [
                    'scroll_offset' => 3,
                    'selected_turn_id' => self::TURN_ID,
                    'expanded_block' => self::TURN_ID,
                    'input_mode' => 'insert',
                ],
            ),
        ];
    }

    private static function eventFromCue(
        Cue $cue,
        string $turnId = self::TURN_ID,
    ): HarnessEvent {
        return HarnessEvent::fromCue(
            cue: $cue,
            sessionId: self::SESSION_ID,
            sequence: $cue->sequence,
            turnId: $turnId,
            id: "event.{$cue->sequence}",
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function effectRequested(
        int $sequence,
        string $turnId,
        string $summary,
        array $arguments,
    ): EffectRequested {
        return new EffectRequested(
            id: "cue.effect.requested.{$sequence}",
            sequence: $sequence,
            activityId: 'activity.ui',
            invocationId: 'invocation.ui',
            agentId: 'agent.ui',
            at: self::at($sequence),
            effectId: 'effect.read',
            kind: EffectKind::FileRead,
            summary: $summary,
            arguments: $arguments,
            requiresApproval: true,
        );
    }

    private static function tokenStop(
        int $sequence,
        string $turnId,
        StopReason $reason,
    ): HarnessEvent {
        return self::eventFromCue(new TokenStop(
            id: "cue.stop.{$sequence}",
            sequence: $sequence,
            activityId: 'activity.ui',
            invocationId: 'invocation.ui',
            agentId: 'agent.ui',
            at: self::at($sequence),
            reason: $reason,
        ), $turnId);
    }

    private static function workspaceRestore(
        int $sequence,
        int $scrollOffset,
        string $turnId,
        InputMode $inputMode,
    ): HarnessEvent {
        return HarnessEvent::marker(
            id: "event.workspace.restore.{$sequence}",
            sessionId: self::SESSION_ID,
            sequence: $sequence,
            cueType: 'agora.workspace.restore',
            source: EventSource::Agora,
            occurredAt: self::at($sequence),
            payload: [
                'scroll_offset' => $scrollOffset,
                'selected_turn_id' => $turnId,
                'expanded_block' => $turnId,
                'input_mode' => $inputMode->value,
            ],
        );
    }

    /**
     * @param list<HarnessEvent> $events
     */
    private static function project(
        array $events,
    ): ProjectionSet {
        $projections = ProjectionSet::empty(self::SESSION_ID);

        foreach ($events as $event) {
            $projections = $projections->apply($event);
        }

        return $projections;
    }

    private static function userEvent(
        int $sequence,
        string $turnId = self::TURN_ID,
        string $text = 'and what makes you so sure?',
    ): HarnessEvent {
        return HarnessEvent::marker(
            id: "event.{$sequence}",
            sessionId: self::SESSION_ID,
            sequence: $sequence,
            cueType: 'agora.turn.user_message',
            source: EventSource::Agora,
            occurredAt: self::at($sequence),
            payload: ['user_text' => $text],
            turnId: $turnId,
        );
    }

    private static function token(
        int $sequence,
        string $text,
        Channel $channel,
        string $turnId = self::TURN_ID,
    ): HarnessEvent {
        return HarnessEvent::fromCue(
            cue: new TokenDelta(
                id: "cue.{$sequence}",
                sequence: $sequence,
                activityId: 'activity.ui',
                invocationId: 'invocation.ui',
                agentId: 'agent.ui',
                at: self::at($sequence),
                text: $text,
                channel: $channel,
            ),
            sessionId: self::SESSION_ID,
            sequence: $sequence,
            turnId: $turnId,
            id: "event.{$sequence}",
        );
    }

    private static function at(
        int $sequence,
    ): DateTimeImmutable {
        return new DateTimeImmutable(sprintf('2026-05-24T13:00:%02dZ', $sequence));
    }
}
