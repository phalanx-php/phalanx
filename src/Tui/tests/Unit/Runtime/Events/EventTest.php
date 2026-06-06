<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Runtime\Events;

use DateTimeImmutable;
use Phalanx\Tui\Runtime\Events\Event;
use Phalanx\Tui\Runtime\Events\EventKind;
use Phalanx\Tui\Runtime\Messages\Envelope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    #[Test]
    public function runtimeEventCarriesLifecycleDataAndRoutableView(): void
    {
        $envelope = Envelope::prompt('review this');
        $event = Event::record(
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
    public function runtimeEventCanonicalFormAndHashAreStable(): void
    {
        $first = Event::record(
            kind: EventKind::WorkCompleted,
            context: ['summary' => 'done'],
            occurredAt: new DateTimeImmutable('2026-05-31T12:00:00+00:00'),
            id: 'evt_test',
        );
        $second = Event::record(
            kind: EventKind::WorkCompleted,
            context: ['summary' => 'done'],
            occurredAt: new DateTimeImmutable('2026-05-31T12:00:00+00:00'),
            id: 'evt_test',
        );

        self::assertSame([
            'id' => 'evt_test',
            'kind' => EventKind::WorkCompleted,
            'occurred_at' => '2026-05-31T12:00:00+00:00',
            'envelope' => null,
            'work_item' => null,
            'work_result' => null,
            'review_verdict' => null,
            'context' => ['summary' => 'done'],
        ], $first->toCanonical());
        self::assertSame($first->toCanonical(), $second->toCanonical());
        self::assertSame($first->hash(), $second->hash());
    }
}
