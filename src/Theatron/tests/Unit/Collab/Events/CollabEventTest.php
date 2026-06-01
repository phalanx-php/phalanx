<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Events;

use DateTimeImmutable;
use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Messages\Envelope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollabEventTest extends TestCase
{
    #[Test]
    public function collabEventCarriesLifecycleDataAndRoutableView(): void
    {
        $envelope = Envelope::prompt('review this');
        $event = CollabEvent::record(
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
    public function collabEventCanonicalFormAndHashAreStable(): void
    {
        $first = CollabEvent::record(
            kind: EventKind::WorkCompleted,
            context: ['summary' => 'done'],
            occurredAt: new DateTimeImmutable('2026-05-31T12:00:00+00:00'),
            id: 'evt_test',
        );
        $second = CollabEvent::record(
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
