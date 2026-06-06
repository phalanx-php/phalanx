<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Runtime\Projection;

use DateTimeImmutable;
use Phalanx\Tui\Runtime\Events\Event;
use Phalanx\Tui\Runtime\Events\EventKind;
use Phalanx\Tui\Runtime\Messages\Address;
use Phalanx\Tui\Runtime\Messages\Envelope;
use Phalanx\Tui\Runtime\Messages\MessageKind;
use Phalanx\Tui\Runtime\Plans\Activity;
use Phalanx\Tui\Runtime\Plans\WorkItem;
use Phalanx\Tui\Runtime\Plans\WorkItemStatus;
use Phalanx\Tui\Runtime\Plans\WorkPlanStatus;
use Phalanx\Tui\Runtime\Plans\WorkResult;
use Phalanx\Tui\Runtime\Projection\Replay;
use Phalanx\Tui\Runtime\State\Store;
use Phalanx\Tui\Runtime\State\TimelineEntry;
use Phalanx\Tui\Runtime\State\TimelineEntryKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReplayTest extends TestCase
{
    #[Test]
    public function replaysFixtureIntoDeterministicStore(): void
    {
        $replay = new Replay();
        $store = $replay(self::happyPathEvents());

        self::assertSame(WorkPlanStatus::Complete, $store->workPlan->plan->status);
        self::assertSame(WorkItemStatus::Done, $store->workPlan->plan->item('work_projection')->status);
        self::assertSame(
            [
                'evt_received:env_prompt',
                'evt_started:work_started:work_projection',
                'evt_done:env_response',
                'evt_done:work_completed:work_projection',
            ],
            array_map(static fn (TimelineEntry $entry): string => $entry->id, $store->messages->entries),
        );
        self::assertSame(TimelineEntryKind::Response, $store->messages->entries[2]->kind);
    }

    #[Test]
    public function freshReplayProducesDeterministicCanonicalPlanState(): void
    {
        $replay = new Replay();

        $first = $replay(self::happyPathEvents())->workPlan->plan->toCanonical();
        $second = $replay(self::happyPathEvents())->workPlan->plan->toCanonical();

        self::assertSame($first, $second);
        self::assertSame('replay', $first['id']);
    }

    #[Test]
    public function canReplayIntoProvidedStore(): void
    {
        $store = new Store();
        $returned = new Replay()->replay(self::happyPathEvents(), $store);

        self::assertSame($store, $returned);
        self::assertSame(WorkPlanStatus::Complete, $store->workPlan->plan->status);
    }

    /**
     * @return list<Event>
     */
    private static function happyPathEvents(): array
    {
        $time = new DateTimeImmutable('2026-05-31T00:00:00+00:00');
        $workItem = new WorkItem(Activity::Thinking, 'Project the store', id: 'work_projection');

        return [
            Event::record(
                EventKind::WorkReceived,
                envelope: Envelope::make(
                    from: Address::user(),
                    to: Address::agent('primary'),
                    kind: MessageKind::Prompt,
                    payload: 'Project the store',
                    id: 'env_prompt',
                ),
                workItem: $workItem,
                occurredAt: $time,
                id: 'evt_received',
            ),
            Event::record(
                EventKind::WorkItemStarted,
                workItem: $workItem,
                occurredAt: $time,
                id: 'evt_started',
            ),
            Event::record(
                EventKind::WorkItemCompleted,
                workItem: $workItem,
                workResult: WorkResult::done(
                    'work_projection',
                    summary: 'Store projected.',
                    envelopes: [
                        Envelope::make(
                            from: Address::agent('primary'),
                            to: Address::user(),
                            kind: MessageKind::Response,
                            payload: 'Store projected.',
                            id: 'env_response',
                        ),
                    ],
                ),
                occurredAt: $time,
                id: 'evt_done',
            ),
        ];
    }
}
