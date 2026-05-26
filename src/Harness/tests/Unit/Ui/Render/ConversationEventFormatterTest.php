<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Ui\Render;

use DateTimeImmutable;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Harness\Ui\Render\ConversationEventFormatter;
use Phalanx\Harness\Ui\Slices\ConversationTurnEvent;
use Phalanx\Panoply\Cue\Effect\Authorized as EffectAuthorized;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
use Phalanx\Panoply\Cue\Effect\Paused as EffectPaused;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationEventFormatterTest extends TestCase
{
    #[Test]
    public function threadLinesGroupRequestedAndExecutedEffectLifecycle(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $events = [
            ConversationTurnEvent::fromCue(new EffectRequested(
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
            )),
            ConversationTurnEvent::fromCue(new EffectExecuted(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                durationMs: 42,
                resultDigest: 'sha256:abc',
            )),
        ];

        $lines = ConversationEventFormatter::threadLines($events);

        self::assertCount(1, $lines);
        self::assertSame(
            'effect executed: file.read eff_1 · Read a strategy note · 42ms · sha256:abc',
            $lines[0]->text,
        );
    }

    #[Test]
    public function threadLinesRenderPausedDeniedAndFailedEffectOutcomes(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');

        self::assertSame(
            'approval needed: file.write eff_paused · Write config · Approval required',
            ConversationEventFormatter::threadLines([
                ConversationTurnEvent::fromCue(new EffectRequested(
                    id: 'cue_1',
                    sequence: 1,
                    activityId: 'act_1',
                    invocationId: 'inv_1',
                    agentId: 'agent_1',
                    at: $at,
                    effectId: 'eff_paused',
                    kind: EffectKind::FileWrite,
                    summary: 'Write config',
                    requiresApproval: true,
                )),
                ConversationTurnEvent::fromCue(new EffectPaused(
                    id: 'cue_2',
                    sequence: 2,
                    activityId: 'act_1',
                    invocationId: 'inv_1',
                    agentId: 'agent_1',
                    at: $at,
                    effectId: 'eff_paused',
                    reason: 'Approval required',
                )),
            ])[0]->text,
        );

        self::assertSame(
            'effect denied: shell.exec eff_denied · Run deploy · policy, user-denied',
            ConversationEventFormatter::threadLines([
                ConversationTurnEvent::fromCue(new EffectRequested(
                    id: 'cue_3',
                    sequence: 3,
                    activityId: 'act_1',
                    invocationId: 'inv_1',
                    agentId: 'agent_1',
                    at: $at,
                    effectId: 'eff_denied',
                    kind: EffectKind::ShellExec,
                    summary: 'Run deploy',
                    requiresApproval: true,
                )),
                ConversationTurnEvent::fromCue(new EffectDenied(
                    id: 'cue_4',
                    sequence: 4,
                    activityId: 'act_1',
                    invocationId: 'inv_1',
                    agentId: 'agent_1',
                    at: $at,
                    effectId: 'eff_denied',
                    reasonCodes: ['policy', 'user-denied'],
                )),
            ])[0]->text,
        );

        self::assertSame(
            'effect failed: code.search eff_failed · Search code · Tool crashed · RuntimeException',
            ConversationEventFormatter::threadLines([
                ConversationTurnEvent::fromCue(new EffectRequested(
                    id: 'cue_5',
                    sequence: 5,
                    activityId: 'act_1',
                    invocationId: 'inv_1',
                    agentId: 'agent_1',
                    at: $at,
                    effectId: 'eff_failed',
                    kind: EffectKind::CodeSearch,
                    summary: 'Search code',
                )),
                ConversationTurnEvent::fromCue(new EffectFailed(
                    id: 'cue_6',
                    sequence: 6,
                    activityId: 'act_1',
                    invocationId: 'inv_1',
                    agentId: 'agent_1',
                    at: $at,
                    effectId: 'eff_failed',
                    reason: 'Tool crashed',
                    errorClass: 'RuntimeException',
                )),
            ])[0]->text,
        );
    }

    #[Test]
    public function threadLinesUseLatestEffectLifecycleState(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $lines = ConversationEventFormatter::threadLines([
            ConversationTurnEvent::fromCue(new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                kind: EffectKind::FileWrite,
                summary: 'Write config',
                requiresApproval: true,
            )),
            ConversationTurnEvent::fromCue(new EffectPaused(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                reason: 'Approval required',
            )),
            ConversationTurnEvent::fromCue(new EffectAuthorized(
                id: 'cue_3',
                sequence: 3,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                grantId: 'grant_1',
            )),
        ]);

        self::assertCount(1, $lines);
        self::assertSame('effect approved: file.write eff_1 · Write config · grant grant_1', $lines[0]->text);
    }

    #[Test]
    public function threadLinesCarryApprovalGrantIntoExecutedLifecycle(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $lines = ConversationEventFormatter::threadLines([
            ConversationTurnEvent::fromCue(new EffectRequested(
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
            )),
            ConversationTurnEvent::fromCue(new EffectAuthorized(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                grantId: 'grant_1',
            )),
            ConversationTurnEvent::fromCue(new EffectExecuted(
                id: 'cue_3',
                sequence: 3,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                durationMs: 42,
                resultDigest: 'sha256:abc',
            )),
        ]);

        self::assertCount(1, $lines);
        self::assertSame(
            'effect executed: file.read eff_1 · Read a strategy note · grant grant_1 · 42ms · sha256:abc',
            $lines[0]->text,
        );
    }

    #[Test]
    public function effectLogSummaryShowsResolutionToolAndOutcomeWithoutInspectionHash(): void
    {
        $event = ConversationTurnEvent::fromEffectLog(new EffectLogRecord(
            id: 'effect_log_1',
            invocationId: 'inv_1',
            kind: 'tool_call',
            toolName: 'read_file',
            argsHash: 'sha256:abc',
            resolution: Resolution::LocalTool,
            outcome: 'ok',
            at: new DateTimeImmutable('2026-05-23T21:00:00Z'),
        ));

        $summary = ConversationEventFormatter::summary($event);

        self::assertSame('local tool: tool_call · local-tool read_file · ok', $summary);
        self::assertStringNotContainsString('args hash', $summary);
        self::assertStringContainsString('args hash sha256:abc', ConversationEventFormatter::detail($event));
    }

    #[Test]
    public function grantDetailShowsApprovalScopeAndInspectionFields(): void
    {
        $event = ConversationTurnEvent::fromGrant(
            new Grant(
                id: 'grant_1',
                subject: 'agent_1',
                allowedEffects: [EffectKind::FileRead, EffectKind::CodeSearch],
                scope: 'session',
                hazardCeiling: Hazard::Medium,
                expiresAt: new DateTimeImmutable('2026-05-24T21:00:00Z'),
                conditions: ['cwd' => 'workspace'],
            ),
            new DateTimeImmutable('2026-05-23T21:00:00Z'),
        );

        $summary = ConversationEventFormatter::summary($event);
        $detail = ConversationEventFormatter::detail($event);

        self::assertStringContainsString('grant: session · medium', $summary);
        self::assertStringContainsString('grant grant_1', $summary);
        self::assertStringNotContainsString('allows file.read, code.search', $summary);
        self::assertStringContainsString('subject agent_1', $detail);
        self::assertStringContainsString('scope session hazard medium', $detail);
        self::assertStringContainsString('allows file.read, code.search', $detail);
        self::assertStringContainsString('conditions {"cwd":"workspace"}', $detail);
        self::assertStringContainsString('expires 2026-05-24T21:00:00+00:00', $detail);
    }
}
