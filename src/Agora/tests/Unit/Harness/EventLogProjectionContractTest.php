<?php

declare(strict_types=1);

namespace Phalanx\Agora\Tests\Unit\Harness;

use Phalanx\Agora\Harness\EventSource;
use Phalanx\Agora\Harness\Exception\DuplicateEventSequence;
use Phalanx\Agora\Harness\Exception\OutOfOrderEventSequence;
use Phalanx\Agora\Harness\Exception\SessionMismatch;
use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\MemoryEventLog;
use Phalanx\Agora\Harness\Projection\ActivityProjection;
use Phalanx\Agora\Harness\Projection\ConversationProjection;
use Phalanx\Agora\Harness\Projection\RuntimeProjection;
use Phalanx\Agora\Harness\Projection\WorkspaceProjection;
use Phalanx\Agora\Harness\ProjectionSet;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Effect\ArgumentsDelta as EffectArgumentsDelta;
use Phalanx\Panoply\Cue\Effect\Authorized as EffectAuthorized;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Paused as EffectPaused;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Invocation\Completed as InvocationCompleted;
use Phalanx\Panoply\Cue\Invocation\Started as InvocationStarted;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\Delta as UsageDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventLogProjectionContractTest extends TestCase
{
    private const string SESSION_ID = 'agora_session:test';
    private const string TURN_ID = 'agora_turn:test';

    #[Test]
    public function memoryEventLogRejectsDuplicateAndRegressedSequences(): void
    {
        $log = new MemoryEventLog();
        $first = self::marker(1);

        self::assertSame($first, $log->append($first));

        $this->expectException(DuplicateEventSequence::class);
        $log->append(self::marker(1));
    }

    #[Test]
    public function memoryEventLogRejectsOutOfOrderSequences(): void
    {
        $log = new MemoryEventLog();
        $log->append(self::marker(1));

        $this->expectException(OutOfOrderEventSequence::class);
        $log->append(self::marker(3));
    }

    #[Test]
    public function projectionHashIsStableAcrossReconstruction(): void
    {
        $first = self::project(self::conversationEvents());
        $second = self::project(self::conversationEvents());

        self::assertSame($first->conversation->state(), $second->conversation->state());
        self::assertSame(
            $first->conversation->checkpoint(self::at(99))->projectionHash,
            $second->conversation->checkpoint(self::at(99))->projectionHash,
        );
    }

    #[Test]
    public function conversationProjectionKeepsThinkingOutOfFinalMessageText(): void
    {
        $projection = self::project(self::conversationEvents())->conversation;
        $turn = $projection->state()['turns'][self::TURN_ID];

        self::assertSame('Final answer.', $turn['message']);
        self::assertSame(['thinking ', 'quietly'], $turn['thinking']);
        self::assertSame([], $turn['reasoning']);
    }

    #[Test]
    public function checkpointPlusTailReplayMatchesFullReplay(): void
    {
        $events = self::conversationEvents();
        $log = new MemoryEventLog();
        foreach ($events as $event) {
            $log->append($event);
        }

        $head = (new ConversationProjection(self::SESSION_ID))
            ->apply($events[0])
            ->apply($events[1])
            ->apply($events[2]);

        $checkpoint = $head->checkpoint(self::at(50));
        $state = $checkpoint->state;

        $fromCheckpoint = new ConversationProjection(
            sessionId: self::SESSION_ID,
            eventSequence: $checkpoint->eventSequence,
            turns: $state['turns'],
            turnOrder: $state['turn_order'],
        );

        foreach ($log->readAfter(self::SESSION_ID, $checkpoint->eventSequence) as $event) {
            $fromCheckpoint = $fromCheckpoint->apply($event);
        }

        $full = self::project($events)->conversation;

        self::assertSame($full->state(), $fromCheckpoint->state());
        self::assertSame(
            $full->checkpoint(self::at(99))->projectionHash,
            $fromCheckpoint->checkpoint(self::at(99))->projectionHash,
        );
    }

    #[Test]
    public function projectionStateDoesNotLeakTheatronImplementationNames(): void
    {
        $state = json_encode(
            self::project(self::conversationEvents())->checkpoints(self::at(99)),
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString('ConversationSlice', $state);
        self::assertStringNotContainsString('DevToolsSlice', $state);
        self::assertStringNotContainsString('ChatScreen', $state);
        self::assertStringNotContainsString('Theatron', $state);
    }

    #[Test]
    public function runtimeProjectionPreservesEffectLifecycleAndAthenaResolution(): void
    {
        $projection = new RuntimeProjection(self::SESSION_ID);
        foreach (self::effectEvents() as $event) {
            $projection = $projection->apply($event);
        }

        $state = $projection->state();

        self::assertSame('executed', $state['effects']['effect.read']['status']);
        self::assertSame('file.read', $state['effects']['effect.read']['kind']);
        self::assertSame('grant.read', $state['effects']['effect.read']['grant_id']);
        self::assertSame(42, $state['effects']['effect.read']['duration_ms']);
        self::assertSame('local-tool', $state['effect_logs']['record.read']['resolution']);
        self::assertSame('file.read', $state['effect_logs']['record.read']['kind']);
    }

    #[Test]
    public function runtimeProjectionPreservesEffectArgumentDeltas(): void
    {
        $projection = (new RuntimeProjection(self::SESSION_ID))
            ->apply(self::effectRequest(1, []))
            ->apply(HarnessEvent::fromCue(new EffectArgumentsDelta(
                id: 'cue.effect.arguments_delta',
                sequence: 2,
                activityId: 'activity.test',
                invocationId: 'invocation.test',
                agentId: 'agent.test',
                at: self::at(2),
                effectId: 'effect.read',
                jsonDelta: '{"path":',
            ), self::SESSION_ID, self::TURN_ID));

        self::assertSame(
            ['{"path":'],
            $projection->state()['effects']['effect.read']['argument_deltas'],
        );
    }

    #[Test]
    public function activityProjectionPreservesUsageAndPendingApprovalResumeRecord(): void
    {
        $projection = new ActivityProjection(self::SESSION_ID);
        foreach (self::activityEvents() as $event) {
            $projection = $projection->apply($event);
        }

        $state = $projection->state();
        $resume = $projection->resumePoint(self::at(99));

        self::assertSame(10, $state['usage']['input_tokens']);
        self::assertSame(20, $state['usage']['output_tokens']);
        self::assertSame(3, $state['usage']['cache_read_tokens']);
        self::assertSame(4, $state['usage']['cache_write_tokens']);
        self::assertSame(37, $state['usage']['total_tokens']);
        self::assertSame(0.12, $state['usage']['cost_usd']);
        self::assertSame('agora_effect:pending-write', $resume->pendingEffectRecordId);
        self::assertSame('effect.write', $resume->serializedContext['pending_effect_id']);
    }

    #[Test]
    public function activityProjectionAccumulatesUsageDeltasUntilFinalUsageArrives(): void
    {
        $projection = (new ActivityProjection(self::SESSION_ID))
            ->apply(self::usageDelta(1, input: 2, output: 3, cacheRead: 0, cacheWrite: 1))
            ->apply(self::usageDelta(2, input: 5, output: 7, cacheRead: 1, cacheWrite: 0));

        self::assertSame([
            'input_tokens' => 7,
            'output_tokens' => 10,
            'cache_read_tokens' => 1,
            'cache_write_tokens' => 1,
            'total_tokens' => 19,
            'cost_usd' => null,
        ], $projection->state()['usage']);
    }

    #[Test]
    public function workspaceProjectionChangesOnlyFromWorkspaceRestoreEvents(): void
    {
        $projection = (new WorkspaceProjection(self::SESSION_ID))
            ->apply(self::messageToken(1, 'hello'));

        self::assertNull($projection->state()['restore']['selected_turn_id']);

        $projection = $projection->apply(HarnessEvent::marker(
            id: 'event.workspace.restore',
            sessionId: self::SESSION_ID,
            sequence: 2,
            cueType: 'agora.workspace.restore',
            source: EventSource::Agora,
            payload: ['selected_turn_id' => self::TURN_ID],
            occurredAt: self::at(2),
        ));

        self::assertSame(self::TURN_ID, $projection->state()['restore']['selected_turn_id']);
    }

    #[Test]
    public function projectionRejectsOutOfOrderReplay(): void
    {
        $projection = new ConversationProjection(self::SESSION_ID);

        $this->expectException(OutOfOrderEventSequence::class);
        $projection->apply(self::messageToken(2, 'late'));
    }

    #[Test]
    public function projectionRejectsCrossSessionEvents(): void
    {
        $projection = new ConversationProjection(self::SESSION_ID);

        $this->expectException(SessionMismatch::class);
        $projection->apply(HarnessEvent::marker(
            id: 'event.other-session',
            sessionId: 'agora_session:other',
            sequence: 1,
            cueType: 'agora.marker',
            source: EventSource::Agora,
            occurredAt: self::at(1),
        ));
    }

    #[Test]
    public function semanticArgumentHashIgnoresAssociativePayloadKeyOrder(): void
    {
        $first = (new RuntimeProjection(self::SESSION_ID))->apply(self::effectRequest(1, [
            'path' => '/tmp/report.txt',
            'mode' => 'read',
        ]));

        $second = (new RuntimeProjection(self::SESSION_ID))->apply(self::effectRequest(1, [
            'mode' => 'read',
            'path' => '/tmp/report.txt',
        ]));

        self::assertSame(
            $first->state()['effects']['effect.read']['arguments_hash'],
            $second->state()['effects']['effect.read']['arguments_hash'],
        );
    }

    /** @param list<HarnessEvent> $events */
    private static function project(
        array $events,
    ): ProjectionSet {
        $projection = ProjectionSet::empty(self::SESSION_ID);

        foreach ($events as $event) {
            $projection = $projection->apply($event);
        }

        return $projection;
    }

    /** @return list<HarnessEvent> */
    private static function conversationEvents(): array
    {
        return [
            HarnessEvent::fromCue(self::invocationStarted(1), self::SESSION_ID, self::TURN_ID),
            self::thinkingToken(2, 'thinking '),
            self::thinkingToken(3, 'quietly'),
            self::messageToken(4, 'Final '),
            self::messageToken(5, 'answer.'),
            HarnessEvent::fromCue(self::invocationCompleted(6), self::SESSION_ID, self::TURN_ID),
        ];
    }

    /** @return list<HarnessEvent> */
    private static function effectEvents(): array
    {
        return [
            self::effectRequest(1, ['path' => '/tmp/report.txt']),
            HarnessEvent::fromCue(new EffectAuthorized(
                id: 'cue.effect.authorized',
                sequence: 2,
                activityId: 'activity.test',
                invocationId: 'invocation.test',
                agentId: 'agent.test',
                at: self::at(2),
                effectId: 'effect.read',
                grantId: 'grant.read',
            ), self::SESSION_ID, self::TURN_ID),
            HarnessEvent::fromCue(new EffectExecuted(
                id: 'cue.effect.executed',
                sequence: 3,
                activityId: 'activity.test',
                invocationId: 'invocation.test',
                agentId: 'agent.test',
                at: self::at(3),
                effectId: 'effect.read',
                durationMs: 42,
                resultDigest: 'sha256:result',
            ), self::SESSION_ID, self::TURN_ID),
            HarnessEvent::fromAthenaEffect(new EffectLogRecord(
                id: 'record.read',
                invocationId: 'invocation.test',
                kind: 'file.read',
                toolName: 'read_file',
                argsHash: 'sha256:args',
                resolution: Resolution::LocalTool,
                outcome: 'executed',
                at: self::at(4),
            ), self::SESSION_ID, 4, self::TURN_ID),
        ];
    }

    /** @return list<HarnessEvent> */
    private static function activityEvents(): array
    {
        return [
            HarnessEvent::fromCue(self::invocationStarted(1), self::SESSION_ID, self::TURN_ID),
            HarnessEvent::fromCue(new FinalUsage(
                id: 'cue.usage.final',
                sequence: 2,
                activityId: 'activity.test',
                invocationId: 'invocation.test',
                agentId: 'agent.test',
                at: self::at(2),
                inputTokens: 10,
                outputTokens: 20,
                cacheReadTokens: 3,
                cacheWriteTokens: 4,
                costUsd: 0.12,
            ), self::SESSION_ID, self::TURN_ID),
            HarnessEvent::fromCue(new EffectPaused(
                id: 'cue.effect.paused',
                sequence: 3,
                activityId: 'activity.test',
                invocationId: 'invocation.test',
                agentId: 'agent.test',
                at: self::at(3),
                effectId: 'effect.write',
                reason: 'approval-required',
            ), self::SESSION_ID, self::TURN_ID, id: 'agora_effect:pending-write'),
        ];
    }

    private static function usageDelta(
        int $sequence,
        int $input,
        int $output,
        int $cacheRead,
        int $cacheWrite,
    ): HarnessEvent {
        return HarnessEvent::fromCue(new UsageDelta(
            id: 'cue.usage.delta.' . $sequence,
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: 'invocation.test',
            agentId: 'agent.test',
            at: self::at($sequence),
            inputTokens: $input,
            outputTokens: $output,
            cacheReadTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
        ), self::SESSION_ID, self::TURN_ID);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function effectRequest(
        int $sequence,
        array $arguments,
    ): HarnessEvent {
        return HarnessEvent::fromCue(new EffectRequested(
            id: 'cue.effect.requested.' . $sequence,
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: 'invocation.test',
            agentId: 'agent.test',
            at: self::at($sequence),
            effectId: 'effect.read',
            kind: EffectKind::FileRead,
            summary: 'Read a report file',
            arguments: $arguments,
            requiresApproval: false,
        ), self::SESSION_ID, self::TURN_ID);
    }

    private static function messageToken(
        int $sequence,
        string $text,
    ): HarnessEvent {
        return HarnessEvent::fromCue(self::token($sequence, $text, Channel::Message), self::SESSION_ID, self::TURN_ID);
    }

    private static function thinkingToken(
        int $sequence,
        string $text,
    ): HarnessEvent {
        return HarnessEvent::fromCue(self::token($sequence, $text, Channel::Thinking), self::SESSION_ID, self::TURN_ID);
    }

    private static function token(
        int $sequence,
        string $text,
        Channel $channel,
    ): TokenDelta {
        return new TokenDelta(
            id: 'cue.output.token.' . $sequence,
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: 'invocation.test',
            agentId: 'agent.test',
            at: self::at($sequence),
            text: $text,
            channel: $channel,
        );
    }

    private static function invocationStarted(
        int $sequence,
    ): Cue {
        return new InvocationStarted(
            id: 'cue.invocation.started',
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: 'invocation.test',
            agentId: 'agent.test',
            at: self::at($sequence),
        );
    }

    private static function invocationCompleted(
        int $sequence,
    ): Cue {
        return new InvocationCompleted(
            id: 'cue.invocation.completed',
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: 'invocation.test',
            agentId: 'agent.test',
            at: self::at($sequence),
            stopReason: StopReason::EndOfTurn,
        );
    }

    private static function marker(
        int $sequence,
    ): HarnessEvent {
        return HarnessEvent::marker(
            id: 'event.' . $sequence,
            sessionId: self::SESSION_ID,
            sequence: $sequence,
            cueType: 'agora.marker',
            source: EventSource::Agora,
            occurredAt: self::at($sequence),
        );
    }

    private static function at(
        int $second,
    ): \DateTimeImmutable {
        return (new \DateTimeImmutable('2026-05-24T12:00:00.000000+00:00'))
            ->modify('+' . $second . ' seconds');
    }
}
