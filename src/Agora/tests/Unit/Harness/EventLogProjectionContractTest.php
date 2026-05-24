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
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
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
    private const string SECOND_TURN_ID = 'agora_turn:second';

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
    public function harnessEventsKeepDurableSequenceSeparateFromSourceCueSequence(): void
    {
        $event = HarnessEvent::fromCue(
            cue: self::token(0, 'hello', Channel::Message),
            sessionId: self::SESSION_ID,
            sequence: 1,
            turnId: self::TURN_ID,
        );

        self::assertSame(1, $event->sequence);
        self::assertSame(0, $event->payload['source_sequence']);
        self::assertSame('cue.output.token.0', $event->cueId);
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
        $effect = $state['effects'][self::effectKey(self::TURN_ID, 'effect.read')];

        self::assertSame('executed', $effect['status']);
        self::assertSame('file.read', $effect['kind']);
        self::assertSame('grant.read', $effect['grant_id']);
        self::assertSame(42, $effect['duration_ms']);
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
            ), self::SESSION_ID, 2, self::TURN_ID));

        self::assertSame(
            ['{"path":'],
            $projection->state()['effects'][self::effectKey(self::TURN_ID, 'effect.read')]['argument_deltas'],
        );
    }

    #[Test]
    public function runtimeProjectionSeparatesRepeatedSourceEffectIdsAcrossTurns(): void
    {
        $projection = (new RuntimeProjection(self::SESSION_ID))
            ->apply(self::effectRequest(1, ['path' => '/tmp/first.txt'], self::TURN_ID))
            ->apply(self::effectRequest(2, ['path' => '/tmp/second.txt'], self::SECOND_TURN_ID));

        $state = $projection->state();

        self::assertSame('/tmp/first.txt', $state['effects'][self::effectKey(self::TURN_ID, 'effect.read')]['arguments']['path']);
        self::assertSame('/tmp/second.txt', $state['effects'][self::effectKey(self::SECOND_TURN_ID, 'effect.read')]['arguments']['path']);
    }

    #[Test]
    public function runtimeProjectionPreservesDeniedPausedAndFailedEffectStates(): void
    {
        $paused = (new RuntimeProjection(self::SESSION_ID))->apply(self::pausedEffect(1));
        $pausedEffect = $paused->state()['effects'][self::effectKey(self::TURN_ID, 'effect.write')];

        self::assertSame('paused', $pausedEffect['status']);
        self::assertSame('approval-required', $pausedEffect['reason']);

        $denied = $paused->apply(self::deniedEffect(2));
        $deniedEffect = $denied->state()['effects'][self::effectKey(self::TURN_ID, 'effect.write')];

        self::assertSame('denied', $deniedEffect['status']);
        self::assertSame(['user-denied'], $deniedEffect['reason_codes']);
        self::assertNull($deniedEffect['reason']);

        $failed = $denied->apply(self::failedEffect(3));
        $effect = $failed->state()['effects'][self::effectKey(self::TURN_ID, 'effect.write')];

        self::assertSame('failed', $effect['status']);
        self::assertSame('tool crashed', $effect['reason']);
        self::assertSame('RuntimeException', $effect['error_class']);
        self::assertArrayNotHasKey('reason_codes', $effect);
        self::assertArrayNotHasKey('result_digest', $effect);
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
    public function activityProjectionDoesNotTreatEventIdsAsEffectRecordIds(): void
    {
        $projection = (new ActivityProjection(self::SESSION_ID))
            ->apply(self::pausedEffect(1));

        $resume = $projection->resumePoint(self::at(99));

        self::assertNull($resume->pendingEffectRecordId);
        self::assertSame('effect.write', $resume->serializedContext['pending_effect_id']);
    }

    #[Test]
    public function activityProjectionClearsPendingApprovalAfterEffectResolution(): void
    {
        $projection = (new ActivityProjection(self::SESSION_ID))
            ->apply(self::pausedEffect(1, effectRecordId: 'agora_effect:pending-write'))
            ->apply(self::authorizedEffect(2))
            ->apply(self::executedEffect(3));

        $state = $projection->state();
        $resume = $projection->resumePoint(self::at(99));

        self::assertSame('streaming', $state['status']);
        self::assertNull($resume->pendingEffectRecordId);
        self::assertArrayNotHasKey('pending_effect_id', $resume->serializedContext);
    }

    #[Test]
    public function activityProjectionMarksDeniedPendingApprovalAsFailed(): void
    {
        $projection = (new ActivityProjection(self::SESSION_ID))
            ->apply(self::pausedEffect(1, effectRecordId: 'agora_effect:pending-write'))
            ->apply(self::deniedEffect(2));

        $state = $projection->state();
        $resume = $projection->resumePoint(self::at(99));

        self::assertSame('failed', $state['status']);
        self::assertNull($resume->pendingEffectRecordId);
        self::assertArrayNotHasKey('pending_effect_id', $resume->serializedContext);
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
    public function activityProjectionAggregatesFinalUsageByInvocation(): void
    {
        $projection = (new ActivityProjection(self::SESSION_ID))
            ->apply(self::finalUsage(1, 'invocation.one', input: 2, output: 3, cacheRead: 1, cacheWrite: 0, costUsd: 0.25))
            ->apply(self::finalUsage(2, 'invocation.two', input: 5, output: 7, cacheRead: 0, cacheWrite: 2, costUsd: 0.5));

        $state = $projection->state();

        self::assertSame([
            'input_tokens' => 7,
            'output_tokens' => 10,
            'cache_read_tokens' => 1,
            'cache_write_tokens' => 2,
            'total_tokens' => 20,
            'cost_usd' => 0.75,
        ], $state['usage']);
        self::assertArrayHasKey('invocation.one', $state['usage_by_invocation']);
        self::assertArrayHasKey('invocation.two', $state['usage_by_invocation']);
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
            $first->state()['effects'][self::effectKey(self::TURN_ID, 'effect.read')]['arguments_hash'],
            $second->state()['effects'][self::effectKey(self::TURN_ID, 'effect.read')]['arguments_hash'],
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
            HarnessEvent::fromCue(self::invocationStarted(1), self::SESSION_ID, 1, self::TURN_ID),
            self::thinkingToken(2, 'thinking '),
            self::thinkingToken(3, 'quietly'),
            self::messageToken(4, 'Final '),
            self::messageToken(5, 'answer.'),
            HarnessEvent::fromCue(self::invocationCompleted(6), self::SESSION_ID, 6, self::TURN_ID),
        ];
    }

    /** @return list<HarnessEvent> */
    private static function effectEvents(): array
    {
        return [
            self::effectRequest(1, ['path' => '/tmp/report.txt']),
            self::authorizedEffect(2, effectId: 'effect.read'),
            self::executedEffect(3, effectId: 'effect.read'),
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

    private static function authorizedEffect(
        int $sequence,
        string $effectId = 'effect.write',
    ): HarnessEvent {
        return HarnessEvent::fromCue(new EffectAuthorized(
            id: 'cue.effect.authorized',
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: 'invocation.test',
            agentId: 'agent.test',
            at: self::at($sequence),
            effectId: $effectId,
            grantId: 'grant.read',
        ), self::SESSION_ID, $sequence, self::TURN_ID);
    }

    private static function executedEffect(
        int $sequence,
        string $effectId = 'effect.write',
    ): HarnessEvent {
        return HarnessEvent::fromCue(new EffectExecuted(
            id: 'cue.effect.executed',
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: 'invocation.test',
            agentId: 'agent.test',
            at: self::at($sequence),
            effectId: $effectId,
            durationMs: 42,
            resultDigest: 'sha256:result',
        ), self::SESSION_ID, $sequence, self::TURN_ID);
    }

    /** @return list<HarnessEvent> */
    private static function activityEvents(): array
    {
        return [
            HarnessEvent::fromCue(self::invocationStarted(1), self::SESSION_ID, 1, self::TURN_ID),
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
            ), self::SESSION_ID, 2, self::TURN_ID),
            self::pausedEffect(3, effectRecordId: 'agora_effect:pending-write'),
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
        ), self::SESSION_ID, $sequence, self::TURN_ID);
    }

    private static function finalUsage(
        int $sequence,
        string $invocationId,
        int $input,
        int $output,
        int $cacheRead,
        int $cacheWrite,
        float $costUsd,
    ): HarnessEvent {
        return HarnessEvent::fromCue(new FinalUsage(
            id: 'cue.usage.final.' . $sequence,
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: $invocationId,
            agentId: 'agent.test',
            at: self::at($sequence),
            inputTokens: $input,
            outputTokens: $output,
            cacheReadTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
            costUsd: $costUsd,
        ), self::SESSION_ID, $sequence, self::TURN_ID);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function effectRequest(
        int $sequence,
        array $arguments,
        string $turnId = self::TURN_ID,
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
        ), self::SESSION_ID, $sequence, $turnId);
    }

    private static function pausedEffect(
        int $sequence,
        ?string $effectRecordId = null,
    ): HarnessEvent {
        $payload = [
            'source_sequence' => $sequence,
            'payload' => [
                'effect_id' => 'effect.write',
                'reason' => 'approval-required',
            ],
        ];

        if ($effectRecordId !== null) {
            $payload['payload']['effect_record_id'] = $effectRecordId;
        }

        return HarnessEvent::marker(
            id: 'event.effect.paused.' . $sequence,
            sessionId: self::SESSION_ID,
            sequence: $sequence,
            cueType: 'cue.effect.paused',
            source: EventSource::Panoply,
            occurredAt: self::at($sequence),
            payload: $payload,
            turnId: self::TURN_ID,
        );
    }

    private static function deniedEffect(
        int $sequence,
    ): HarnessEvent {
        return HarnessEvent::fromCue(new EffectDenied(
            id: 'cue.effect.denied',
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: 'invocation.test',
            agentId: 'agent.test',
            at: self::at($sequence),
            effectId: 'effect.write',
            reasonCodes: ['user-denied'],
        ), self::SESSION_ID, $sequence, self::TURN_ID);
    }

    private static function failedEffect(
        int $sequence,
    ): HarnessEvent {
        return HarnessEvent::fromCue(new EffectFailed(
            id: 'cue.effect.failed',
            sequence: $sequence,
            activityId: 'activity.test',
            invocationId: 'invocation.test',
            agentId: 'agent.test',
            at: self::at($sequence),
            effectId: 'effect.write',
            reason: 'tool crashed',
            errorClass: 'RuntimeException',
        ), self::SESSION_ID, $sequence, self::TURN_ID);
    }

    private static function messageToken(
        int $sequence,
        string $text,
    ): HarnessEvent {
        return HarnessEvent::fromCue(self::token($sequence, $text, Channel::Message), self::SESSION_ID, $sequence, self::TURN_ID);
    }

    private static function thinkingToken(
        int $sequence,
        string $text,
    ): HarnessEvent {
        return HarnessEvent::fromCue(self::token($sequence, $text, Channel::Thinking), self::SESSION_ID, $sequence, self::TURN_ID);
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

    private static function effectKey(
        string $turnId,
        string $effectId,
    ): string {
        return $turnId . ':' . $effectId;
    }

    private static function at(
        int $second,
    ): \DateTimeImmutable {
        return (new \DateTimeImmutable('2026-05-24T12:00:00.000000+00:00'))
            ->modify('+' . $second . ' seconds');
    }
}
