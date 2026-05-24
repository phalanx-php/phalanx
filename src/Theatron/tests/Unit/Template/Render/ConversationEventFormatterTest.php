<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Render;

use DateTimeImmutable;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Theatron\Template\Render\ConversationEventFormatter;
use Phalanx\Theatron\Template\Slice\ConversationTurnEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationEventFormatterTest extends TestCase
{
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
