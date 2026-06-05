<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Collab\Projection;

use DateTimeImmutable;
use Phalanx\Tui\Collab\Events\AgentHarnessEvent;
use Phalanx\Tui\Collab\Events\EventKind;
use Phalanx\Tui\Collab\Messages\Address;
use Phalanx\Tui\Collab\Messages\Envelope;
use Phalanx\Tui\Collab\Messages\MessageKind;
use Phalanx\Tui\Collab\Plans\Activity;
use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkItemStatus;
use Phalanx\Tui\Collab\Plans\WorkPlanStatus;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\Projection\AgentHarnessReplay;
use Phalanx\Tui\Collab\State\AgentHarnessStore;
use Phalanx\Tui\Collab\State\TimelineEntry;
use Phalanx\Tui\Collab\State\TimelineEntryKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentHarnessReplayTest extends TestCase
{
    #[Test]
    public function replaysFixtureIntoDeterministicStore(): void
    {
        $replay = new AgentHarnessReplay();
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
        $replay = new AgentHarnessReplay();

        $first = $replay(self::happyPathEvents())->workPlan->plan->toCanonical();
        $second = $replay(self::happyPathEvents())->workPlan->plan->toCanonical();

        self::assertSame($first, $second);
        self::assertSame('replay', $first['id']);
    }

    #[Test]
    public function canReplayIntoProvidedStore(): void
    {
        $store = new AgentHarnessStore();
        $returned = new AgentHarnessReplay()->replay(self::happyPathEvents(), $store);

        self::assertSame($store, $returned);
        self::assertSame(WorkPlanStatus::Complete, $store->workPlan->plan->status);
    }

    /**
     * @return list<AgentHarnessEvent>
     */
    private static function happyPathEvents(): array
    {
        $time = new DateTimeImmutable('2026-05-31T00:00:00+00:00');
        $workItem = new WorkItem(Activity::Thinking, 'Project the store', id: 'work_projection');

        return [
            AgentHarnessEvent::record(
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
            AgentHarnessEvent::record(
                EventKind::WorkItemStarted,
                workItem: $workItem,
                occurredAt: $time,
                id: 'evt_started',
            ),
            AgentHarnessEvent::record(
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
