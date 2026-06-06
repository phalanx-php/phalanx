<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Runtime\Lifecycle;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Runtime\Events\Event;
use Phalanx\Tui\Runtime\Events\EventKind;
use Phalanx\Tui\Runtime\Lifecycle\Loop;
use Phalanx\Tui\Runtime\Lifecycle\LoopStage;
use Phalanx\Tui\Runtime\Participants\AgentParticipant;
use Phalanx\Tui\Runtime\Participants\Preparer;
use Phalanx\Tui\Runtime\Participants\Reactor;
use Phalanx\Tui\Runtime\Participants\Reviewer;
use Phalanx\Tui\Runtime\Plans\Activity;
use Phalanx\Tui\Runtime\Plans\WorkItem;
use Phalanx\Tui\Runtime\Plans\WorkItemStatus;
use Phalanx\Tui\Runtime\Plans\WorkPlan;
use Phalanx\Tui\Runtime\Plans\WorkPlanItem;
use Phalanx\Tui\Runtime\Plans\WorkPlanStatus;
use Phalanx\Tui\Runtime\Plans\WorkResult;
use Phalanx\Tui\Runtime\Projection\Replay;
use Phalanx\Tui\Runtime\Reviews\ReviewVerdict;
use Phalanx\Tui\Runtime\State\Store;
use Phalanx\Tui\Runtime\State\TimelineEntry;
use Phalanx\Tui\Runtime\State\WorkPlanSlice;
use Phalanx\Tui\Runtime\WorkContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LoopTest extends TestCase
{
    #[Test]
    public function preparerPrimaryAgentParticipantAndDefaultReviewCompleteTheLoop(): void
    {
        $calls = new \ArrayObject();
        $events = new \ArrayObject();
        $reactStages = new \ArrayObject();
        $ctx = $this->ctx();
        $loop = new Loop(
            primary: self::doneAgentParticipant('primary', $calls),
            preparers: [self::preparer(new WorkItem(Activity::Testing, 'Run tests', id: 'work_a'))],
            reactors: [self::reactor($events, $reactStages)],
        );

        $status = $loop($ctx);

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(LoopStage::Complete, $ctx->stage);
        self::assertSame(['primary:work_a'], $calls->getArrayCopy());
        self::assertSame(
            [
                EventKind::WorkReceived,
                EventKind::WorkPrepared,
                EventKind::WorkDistributed,
                EventKind::WorkItemStarted,
                EventKind::WorkItemCompleted,
                EventKind::WorkReviewed,
                EventKind::WorkCompleted,
            ],
            $events->getArrayCopy(),
        );
        self::assertSame(
            [LoopStage::React->value],
            array_values(array_unique(array_map(
                static fn(LoopStage $stage): string => $stage->value,
                $reactStages->getArrayCopy(),
            ))),
        );
    }

    #[Test]
    public function firstSupportingAgentParticipantRunsBeforePrimaryFallback(): void
    {
        $calls = new \ArrayObject();
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Editing, 'Patch code', tags: ['php'], id: 'work_patch')));
        $loop = new Loop(
            primary: self::doneAgentParticipant('primary', $calls),
            participants: [
                self::tagAgentParticipant('php-specialist', 'php', $calls),
            ],
        );

        $status = $loop($ctx);

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(['php-specialist:work_patch'], $calls->getArrayCopy());
    }

    #[Test]
    public function fullLoopEventStreamReplaysToEquivalentStore(): void
    {
        $events = new LoopEventLog();
        $liveStore = new Store();
        $ctx = new WorkContext($this->createStub(TaskScope::class), $liveStore);
        $loop = new Loop(
            primary: self::doneAgentParticipant('primary', new \ArrayObject()),
            preparers: [self::preparer(new WorkItem(Activity::Testing, 'Run tests', id: 'work_a'))],
            reactors: [self::eventCapture($events)],
        );

        $status = $loop($ctx);
        $replayed = new Replay()->replay($events->events);

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(self::planRows($liveStore), self::planRows($replayed));
        self::assertSame(self::timelineRows($liveStore), self::timelineRows($replayed));
        self::assertSame(
            array_map(static fn (ReviewVerdict $verdict): string => $verdict->status->value, $liveStore->reviews->verdicts),
            array_map(static fn (ReviewVerdict $verdict): string => $verdict->status->value, $replayed->reviews->verdicts),
        );
    }

    #[Test]
    public function preseededPlanEventStreamReplaysToEquivalentStore(): void
    {
        $events = new LoopEventLog();
        $liveStore = new Store();
        $liveStore->workPlan = new WorkPlanSlice(WorkPlan::start(new WorkItem(
            Activity::Editing,
            'Patch code',
            id: 'work_patch',
        )));
        $ctx = new WorkContext($this->createStub(TaskScope::class), $liveStore);
        $loop = new Loop(
            primary: self::doneAgentParticipant('primary', new \ArrayObject()),
            reactors: [self::eventCapture($events)],
        );

        $status = $loop($ctx);
        $replayed = new Replay()->replay($events->events);

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(self::planRows($liveStore), self::planRows($replayed));
        self::assertSame(self::timelineRows($liveStore), self::timelineRows($replayed));
    }

    #[Test]
    public function preparerAddedWorkIsReplayable(): void
    {
        $events = new LoopEventLog();
        $ctx = $this->ctx();
        $loop = new Loop(
            primary: self::doneAgentParticipant('primary', new \ArrayObject()),
            preparers: [self::preparer(new WorkItem(Activity::Testing, 'Run tests', id: 'work_a'))],
            reactors: [self::eventCapture($events)],
        );

        $loop($ctx);
        $replayed = new Replay()->replay($events->events);

        self::assertSame(WorkItemStatus::Done, $replayed->workPlan->plan->item('work_a')->status);
    }

    #[Test]
    public function loopOwnedProjectionDoesNotDrainPendingUserlandEvents(): void
    {
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Testing, 'Run tests', id: 'work_a')));
        $ctx->project(Event::record(EventKind::WorkDistributed, id: 'evt_prequeued_distribution'));
        $loop = new Loop(
            primary: self::doneAgentParticipant('primary', new \ArrayObject()),
        );

        $loop($ctx);

        self::assertSame(
            ['evt_prequeued_distribution'],
            array_map(
                static fn (Event $event): string => $event->id,
                $ctx->drainProjectedEvents(EventKind::WorkDistributed),
            ),
        );
    }

    #[Test]
    public function reviewerRevisionAppendsFollowUpWorkAndRunsAnotherPass(): void
    {
        $calls = new \ArrayObject();
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch')));
        $loop = new Loop(
            primary: self::doneAgentParticipant('primary', $calls),
            reviewers: [
                new class implements Reviewer {
                    private int $calls = 0;

                    public function __invoke(WorkContext $ctx): ReviewVerdict
                    {
                        $this->calls++;
                        if ($this->calls === 1) {
                            return ReviewVerdict::revise(
                                'Need tests.',
                                [new WorkItem(Activity::Testing, 'Add focused tests', id: 'work_tests')],
                            );
                        }

                        return ReviewVerdict::approve();
                    }
                },
            ],
        );

        $status = $loop($ctx);

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(['primary:work_patch', 'primary:work_tests'], $calls->getArrayCopy());
        self::assertSame('work_tests', $ctx->plan->item('work_tests')->workItem->id);
    }

    #[Test]
    public function reviewerRejectionAbortsCompletedWork(): void
    {
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch')));
        $loop = new Loop(
            primary: self::doneAgentParticipant('primary', new \ArrayObject()),
            reviewers: [
                new class implements Reviewer {
                    public function __invoke(WorkContext $ctx): ReviewVerdict
                    {
                        return ReviewVerdict::reject('Unsafe change.');
                    }
                },
            ],
        );

        $status = $loop($ctx);

        self::assertSame(WorkPlanStatus::Aborted, $status);
        self::assertSame('Unsafe change.', $ctx->plan->statusReason);
    }

    #[Test]
    public function primaryFallbackMustSupportPreferredParticipant(): void
    {
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(
            Activity::Editing,
            'Patch code',
            preferredParticipant: \Phalanx\Tui\Runtime\Messages\Address::agent('other'),
            id: 'work_patch',
        )));
        $loop = new Loop(
            primary: self::preferredAgentParticipant('primary', 'primary', new \ArrayObject()),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No participant supports work item "work_patch".');

        try {
            $loop($ctx);
        } finally {
            self::assertSame(WorkItemStatus::Pending, $ctx->plan->item('work_patch')->status);
        }
    }

    #[Test]
    public function participantFailureBecomesInterruptedFailedWork(): void
    {
        $events = new \ArrayObject();
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch')));
        $error = new \RuntimeException('tool failed');
        $loop = new Loop(
            primary: self::throwingAgentParticipant($error),
            reactors: [self::resultReactor($events)],
        );

        $status = $loop($ctx);

        self::assertSame(WorkPlanStatus::Suspended, $status);
        self::assertSame($error, $ctx->plan->item('work_patch')->result?->error);
        self::assertSame([EventKind::WorkInterrupted], $events->getArrayCopy());
    }

    #[Test]
    public function reactorFailureRestoresPreviousLoopStage(): void
    {
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch')));
        $error = new \RuntimeException('reactor failed');
        $loop = new Loop(
            primary: self::doneAgentParticipant('primary', new \ArrayObject()),
            reactors: [
                new class ($error) implements Reactor {
                    public function __construct(private \RuntimeException $error)
                    {
                    }

                    public function __invoke(WorkContext $ctx, Event $event): void
                    {
                        throw $this->error;
                    }
                },
            ],
        );

        try {
            $loop($ctx);
            self::fail('Expected reactor failure to bubble.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
            self::assertSame(LoopStage::Receive, $ctx->stage);
        }
    }

    #[Test]
    public function blockedAgentParticipantSuspendsThePlan(): void
    {
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Researching, 'Wait for token', id: 'work_wait')));
        $loop = new Loop(
            primary: new class implements AgentParticipant {
                public function __invoke(WorkContext $ctx, WorkPlanItem $item): WorkResult
                {
                    return WorkResult::blocked($item->workItem->id, 'missing token');
                }

                public function supports(WorkContext $ctx, WorkPlanItem $item): bool
                {
                    return true;
                }
            },
        );

        $status = $loop($ctx);

        self::assertSame(WorkPlanStatus::Suspended, $status);
        self::assertSame('missing token', $ctx->plan->item('work_wait')->blockedReason);
    }

    /**
     * @return list<array{id: string, status: string}>
     */
    private static function planRows(Store $store): array
    {
        return array_map(
            static fn (WorkPlanItem $item): array => [
                'id' => $item->workItem->id,
                'status' => $item->status->value,
            ],
            $store->workPlan->plan->items(),
        );
    }

    /**
     * @return list<array{kind: string, summary: string, work: ?string, status: ?string}>
     */
    private static function timelineRows(Store $store): array
    {
        return array_map(
            static fn (TimelineEntry $entry): array => [
                'kind' => $entry->kind->value,
                'summary' => $entry->summary,
                'work' => $entry->workItemId,
                'status' => $entry->status,
            ],
            $store->messages->entries,
        );
    }

    private static function preparer(WorkItem $item): Preparer
    {
        return new class ($item) implements Preparer {
            public function __construct(private WorkItem $item)
            {
            }

            public function __invoke(WorkContext $ctx): void
            {
                $ctx->append($this->item);
            }
        };
    }

    /** @param \ArrayObject<int, string> $calls */
    private static function doneAgentParticipant(string $name, \ArrayObject $calls): AgentParticipant
    {
        return new class ($name, $calls) implements AgentParticipant {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(
                private string $name,
                private \ArrayObject $calls,
            ) {
            }

            public function __invoke(WorkContext $ctx, WorkPlanItem $item): WorkResult
            {
                $this->calls[] = $this->name . ':' . $item->workItem->id;

                return WorkResult::done($item->workItem->id, summary: 'done');
            }

            public function supports(WorkContext $ctx, WorkPlanItem $item): bool
            {
                return true;
            }
        };
    }

    /** @param \ArrayObject<int, string> $calls */
    private static function tagAgentParticipant(string $name, string $tag, \ArrayObject $calls): AgentParticipant
    {
        return new class ($name, $tag, $calls) implements AgentParticipant {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(
                private string $name,
                private string $tag,
                private \ArrayObject $calls,
            ) {
            }

            public function __invoke(WorkContext $ctx, WorkPlanItem $item): WorkResult
            {
                $this->calls[] = $this->name . ':' . $item->workItem->id;

                return WorkResult::done($item->workItem->id, summary: 'done');
            }

            public function supports(WorkContext $ctx, WorkPlanItem $item): bool
            {
                return in_array($this->tag, $item->workItem->tags, true);
            }
        };
    }

    /** @param \ArrayObject<int, string> $calls */
    private static function preferredAgentParticipant(string $name, string $agentId, \ArrayObject $calls): AgentParticipant
    {
        return new class ($name, $agentId, $calls) implements AgentParticipant {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(
                private string $name,
                private string $agentId,
                private \ArrayObject $calls,
            ) {
            }

            public function __invoke(WorkContext $ctx, WorkPlanItem $item): WorkResult
            {
                $this->calls[] = $this->name . ':' . $item->workItem->id;

                return WorkResult::done($item->workItem->id, summary: 'done');
            }

            public function supports(WorkContext $ctx, WorkPlanItem $item): bool
            {
                $preferred = $item->workItem->preferredParticipant;

                return $preferred === null || $preferred->equals(\Phalanx\Tui\Runtime\Messages\Address::agent($this->agentId));
            }
        };
    }

    private static function throwingAgentParticipant(\Throwable $error): AgentParticipant
    {
        return new class ($error) implements AgentParticipant {
            public function __construct(private \Throwable $error)
            {
            }

            public function __invoke(WorkContext $ctx, WorkPlanItem $item): WorkResult
            {
                throw $this->error;
            }

            public function supports(WorkContext $ctx, WorkPlanItem $item): bool
            {
                return true;
            }
        };
    }

    /**
     * @param \ArrayObject<int, EventKind> $events
     * @param \ArrayObject<int, LoopStage> $stages
     */
    private static function reactor(\ArrayObject $events, \ArrayObject $stages): Reactor
    {
        return new class ($events, $stages) implements Reactor {
            /**
             * @param \ArrayObject<int, EventKind> $events
             * @param \ArrayObject<int, LoopStage> $stages
             */
            public function __construct(
                private \ArrayObject $events,
                private \ArrayObject $stages,
            ) {
            }

            public function __invoke(WorkContext $ctx, Event $event): void
            {
                $this->events[] = $event->kind;
                $this->stages[] = $ctx->stage;
            }
        };
    }

    private static function eventCapture(LoopEventLog $events): Reactor
    {
        return new class ($events) implements Reactor {
            public function __construct(private LoopEventLog $events)
            {
            }

            public function __invoke(WorkContext $ctx, Event $event): void
            {
                $this->events->record($event);
            }
        };
    }

    /** @param \ArrayObject<int, EventKind> $events */
    private static function resultReactor(\ArrayObject $events): Reactor
    {
        return new class ($events) implements Reactor {
            /** @param \ArrayObject<int, EventKind> $events */
            public function __construct(private \ArrayObject $events)
            {
            }

            public function __invoke(WorkContext $ctx, Event $event): void
            {
                if ($event->workResult !== null) {
                    $this->events[] = $event->kind;
                }
            }
        };
    }

    private function ctx(?WorkPlan $plan = null): WorkContext
    {
        $store = new Store();
        if ($plan !== null) {
            $store->workPlan = new WorkPlanSlice($plan);
        }

        return new WorkContext($this->createStub(TaskScope::class), $store);
    }
}

class LoopEventLog
{
    /** @var list<Event> */
    private(set) array $events = [];

    public function record(Event $event): void
    {
        $this->events[] = $event;
    }
}
