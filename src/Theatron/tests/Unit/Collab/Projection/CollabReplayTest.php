<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Projection;

use DateTimeImmutable;
use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Messages\Address;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Messages\MessageKind;
use Phalanx\Theatron\Collab\Plans\Activity;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkItemStatus;
use Phalanx\Theatron\Collab\Plans\WorkPlanStatus;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Projection\CollabReplay;
use Phalanx\Theatron\Collab\State\CollabStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollabReplayTest extends TestCase
{
    #[Test]
    public function replaysFixtureIntoDeterministicStore(): void
    {
        $store = new CollabReplay()(self::happyPathEvents());

        self::assertSame(WorkPlanStatus::Complete, $store->workPlan->plan->status);
        self::assertSame(WorkItemStatus::Done, $store->workPlan->plan->item('work_projection')->status);
        self::assertSame(
            ['evt_received:env_prompt', 'evt_started:work_started:work_projection', 'evt_done:env_response', 'evt_done:work_completed:work_projection'],
            array_map(static fn ($entry): string => $entry->id, $store->messages->entries),
        );
    }

    #[Test]
    public function canReplayIntoProvidedStore(): void
    {
        $store = new CollabStore();
        $returned = new CollabReplay()->replay(self::happyPathEvents(), $store);

        self::assertSame($store, $returned);
        self::assertSame(WorkPlanStatus::Complete, $store->workPlan->plan->status);
    }

    /**
     * @return list<CollabEvent>
     */
    private static function happyPathEvents(): array
    {
        $time = new DateTimeImmutable('2026-05-31T00:00:00+00:00');
        $workItem = new WorkItem(Activity::Thinking, 'Project the store', id: 'work_projection');

        return [
            CollabEvent::record(
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
            CollabEvent::record(
                EventKind::WorkItemStarted,
                workItem: $workItem,
                occurredAt: $time,
                id: 'evt_started',
            ),
            CollabEvent::record(
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
