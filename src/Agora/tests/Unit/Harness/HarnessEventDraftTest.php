<?php

declare(strict_types=1);

namespace Phalanx\Agora\Tests\Unit\Harness;

use DateTimeImmutable;
use Phalanx\Agora\Harness\EventSource;
use Phalanx\Agora\Harness\Persistence\HarnessEventDraft;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('agora')]
final class HarnessEventDraftTest extends TestCase
{
    #[Test]
    public function fromCueCreatesCorrectDraft(): void
    {
        $at = new DateTimeImmutable('2026-05-24T12:00:00.000000+00:00');
        $cue = new ActivityStarted(
            id: 'cue-activity-apollo',
            sequence: 1,
            activityId: 'activity-olympus',
            invocationId: null,
            agentId: null,
            at: $at,
        );

        $draft = HarnessEventDraft::fromCue($cue, 'session-apollo', 'turn-zeus');

        self::assertSame('session-apollo', $draft->sessionId);
        self::assertSame('turn-zeus', $draft->turnId);
        self::assertSame('cue-activity-apollo', $draft->cueId);
        self::assertSame('cue.activity.started', $draft->cueType);
        self::assertSame(EventSource::Panoply, $draft->source);
        self::assertSame('panoply:cue-activity-apollo', $draft->sourceKey);
        self::assertSame($at, $draft->occurredAt);
        self::assertSame($at, $draft->receivedAt);
        self::assertNull($draft->channel);
    }

    #[Test]
    public function markerCreatesCorrectDraft(): void
    {
        $at = new DateTimeImmutable('2026-05-24T14:30:00.000000+00:00');

        $draft = HarnessEventDraft::marker(
            sessionId: 'session-poseidon',
            cueType: 'agora.turn.started',
            source: EventSource::Agora,
            occurredAt: $at,
            turnId: 'turn-triton',
        );

        self::assertSame('session-poseidon', $draft->sessionId);
        self::assertSame('turn-triton', $draft->turnId);
        self::assertNull($draft->cueId);
        self::assertSame('agora.turn.started', $draft->cueType);
        self::assertSame(EventSource::Agora, $draft->source);
        self::assertNull($draft->channel);
        self::assertSame($at, $draft->occurredAt);
        self::assertSame($at, $draft->receivedAt);
        self::assertStringStartsWith('agora:', $draft->sourceKey);
        self::assertSame(70, strlen($draft->sourceKey));
    }

    #[Test]
    public function toRecordDataFormatsTimestampsAsUtc(): void
    {
        $at = new DateTimeImmutable('2026-05-24T12:00:00.000000+00:00');
        $cue = new ActivityStarted(
            id: 'cue-activity-hephaestus',
            sequence: 1,
            activityId: 'activity-forge',
            invocationId: null,
            agentId: null,
            at: $at,
        );

        $data = HarnessEventDraft::fromCue($cue, 'session-hephaestus')->toRecordData();

        self::assertSame('2026-05-24T12:00:00.000000Z', $data['occurred']);
        self::assertSame('2026-05-24T12:00:00.000000Z', $data['received']);
        self::assertSame('cue-activity-hephaestus', $data['cueid']);
        self::assertSame('cue.activity.started', $data['cuetype']);
        self::assertSame('panoply', $data['source']);
        self::assertSame('panoply:cue-activity-hephaestus', $data['sourcekey']);
    }
}
