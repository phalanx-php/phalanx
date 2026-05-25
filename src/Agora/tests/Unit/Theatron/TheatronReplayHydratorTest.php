<?php

declare(strict_types=1);

namespace Phalanx\Agora\Tests\Unit\Theatron;

use DateTimeImmutable;
use Phalanx\Agora\Harness\EventSource;
use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionSet;
use Phalanx\Agora\Harness\Replay\ReplaySession;
use Phalanx\Agora\Theatron\TheatronReplayHydrator;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
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
    ): HarnessEvent {
        return HarnessEvent::marker(
            id: "event.{$sequence}",
            sessionId: self::SESSION_ID,
            sequence: $sequence,
            cueType: 'agora.turn.user_message',
            source: EventSource::Agora,
            occurredAt: self::at($sequence),
            payload: ['user_text' => 'and what makes you so sure?'],
            turnId: self::TURN_ID,
        );
    }

    private static function token(
        int $sequence,
        string $text,
        Channel $channel,
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
            turnId: self::TURN_ID,
            id: "event.{$sequence}",
        );
    }

    private static function at(
        int $sequence,
    ): DateTimeImmutable {
        return new DateTimeImmutable(sprintf('2026-05-24T13:00:%02dZ', $sequence));
    }
}
