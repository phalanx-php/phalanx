<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Event;

use DateTimeImmutable;
use Phalanx\Harness\Event\EventKind;
use Phalanx\Harness\Event\HarnessEvent;
use Phalanx\Harness\Message\Envelope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessEventTest extends TestCase
{
    #[Test]
    public function harnessEventCarriesLifecycleDataAndRoutableView(): void
    {
        $envelope = Envelope::prompt('review this');
        $event = HarnessEvent::record(
            kind: EventKind::WorkReceived,
            envelope: $envelope,
            context: ['round' => 'round_1'],
            occurredAt: new DateTimeImmutable('2026-05-31T12:00:00+00:00'),
            id: 'evt_test',
        );

        $routable = $event->routable('received');

        self::assertSame('evt_test', $event->id);
        self::assertSame(EventKind::WorkReceived, $event->kind);
        self::assertSame($envelope, $event->envelope);
        self::assertSame('evt_test', $routable->id);
        self::assertSame('received', $routable->summary);
    }

    #[Test]
    public function harnessEventCanonicalFormAndHashAreStable(): void
    {
        $first = HarnessEvent::record(
            kind: EventKind::WorkCompleted,
            context: ['summary' => 'done'],
            occurredAt: new DateTimeImmutable('2026-05-31T12:00:00+00:00'),
            id: 'evt_test',
        );
        $second = HarnessEvent::record(
            kind: EventKind::WorkCompleted,
            context: ['summary' => 'done'],
            occurredAt: new DateTimeImmutable('2026-05-31T12:00:00+00:00'),
            id: 'evt_test',
        );

        self::assertSame($first->toCanonical(), $second->toCanonical());
        self::assertSame($first->hash(), $second->hash());
    }
}
