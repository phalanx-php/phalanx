<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Lifecycle;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Lifecycle\CollaborationLoop;
use Phalanx\Theatron\Collab\Lifecycle\LoopStage;
use Phalanx\Theatron\Collab\Participants\Collaborator;
use Phalanx\Theatron\Collab\Participants\Preparer;
use Phalanx\Theatron\Collab\Participants\Reactor;
use Phalanx\Theatron\Collab\Participants\Reviewer;
use Phalanx\Theatron\Collab\Plans\Activity;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkItemStatus;
use Phalanx\Theatron\Collab\Plans\WorkPlan;
use Phalanx\Theatron\Collab\Plans\WorkPlanItem;
use Phalanx\Theatron\Collab\Plans\WorkPlanStatus;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Projection\CollabReplay;
use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\State\TimelineEntry;
use Phalanx\Theatron\Collab\State\WorkPlanSlice;
use Phalanx\Theatron\Collab\WorkContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollaborationLoopTest extends TestCase
{
    #[Test]
    public function preparerPrimaryCollaboratorAndDefaultReviewCompleteTheLoop(): void
    {
        $calls = new \ArrayObject();
        $events = new \ArrayObject();
        $reactStages = new \ArrayObject();
        $ctx = $this->ctx();
        $loop = new CollaborationLoop(
            primary: self::doneCollaborator('primary', $calls),
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
    public function firstSupportingCollaboratorRunsBeforePrimaryFallback(): void
    {
        $calls = new \ArrayObject();
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Editing, 'Patch code', tags: ['php'], id: 'work_patch')));
        $loop = new CollaborationLoop(
            primary: self::doneCollaborator('primary', $calls),
            collaborators: [
                self::tagCollaborator('php-specialist', 'php', $calls),
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
        $liveStore = new CollabStore();
        $ctx = new WorkContext($this->createStub(TaskScope::class), $liveStore);
        $loop = new CollaborationLoop(
            primary: self::doneCollaborator('primary', new \ArrayObject()),
            preparers: [self::preparer(new WorkItem(Activity::Testing, 'Run tests', id: 'work_a'))],
            reactors: [self::eventCapture($events)],
        );

        $status = $loop($ctx);
        $replayed = new CollabReplay()->replay($events->events);

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
        $liveStore = new CollabStore();
        $liveStore->workPlan = new WorkPlanSlice(WorkPlan::start(new WorkItem(
            Activity::Editing,
            'Patch code',
            id: 'work_patch',
        )));
        $ctx = new WorkContext($this->createStub(TaskScope::class), $liveStore);
        $loop = new CollaborationLoop(
            primary: self::doneCollaborator('primary', new \ArrayObject()),
            reactors: [self::eventCapture($events)],
        );

        $status = $loop($ctx);
        $replayed = new CollabReplay()->replay($events->events);

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(self::planRows($liveStore), self::planRows($replayed));
        self::assertSame(self::timelineRows($liveStore), self::timelineRows($replayed));
    }

    #[Test]
    public function preparerAddedWorkIsReplayable(): void
    {
        $events = new LoopEventLog();
        $ctx = $this->ctx();
        $loop = new CollaborationLoop(
            primary: self::doneCollaborator('primary', new \ArrayObject()),
            preparers: [self::preparer(new WorkItem(Activity::Testing, 'Run tests', id: 'work_a'))],
            reactors: [self::eventCapture($events)],
        );

        $loop($ctx);
        $replayed = new CollabReplay()->replay($events->events);

        self::assertSame(WorkItemStatus::Done, $replayed->workPlan->plan->item('work_a')->status);
    }

    #[Test]
    public function loopOwnedProjectionDoesNotDrainPendingUserlandEvents(): void
    {
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Testing, 'Run tests', id: 'work_a')));
        $ctx->project(CollabEvent::record(EventKind::WorkDistributed, id: 'evt_prequeued_distribution'));
        $loop = new CollaborationLoop(
            primary: self::doneCollaborator('primary', new \ArrayObject()),
        );

        $loop($ctx);

        self::assertSame(
            ['evt_prequeued_distribution'],
            array_map(
                static fn (CollabEvent $event): string => $event->id,
                $ctx->drainProjectedEvents(EventKind::WorkDistributed),
            ),
        );
    }

    #[Test]
    public function reviewerRevisionAppendsFollowUpWorkAndRunsAnotherPass(): void
    {
        $calls = new \ArrayObject();
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch')));
        $loop = new CollaborationLoop(
            primary: self::doneCollaborator('primary', $calls),
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
        $loop = new CollaborationLoop(
            primary: self::doneCollaborator('primary', new \ArrayObject()),
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
            preferredParticipant: \Phalanx\Theatron\Collab\Messages\Address::agent('other'),
            id: 'work_patch',
        )));
        $loop = new CollaborationLoop(
            primary: self::preferredCollaborator('primary', 'primary', new \ArrayObject()),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No collaborator supports work item "work_patch".');

        try {
            $loop($ctx);
        } finally {
            self::assertSame(WorkItemStatus::Pending, $ctx->plan->item('work_patch')->status);
        }
    }

    #[Test]
    public function collaboratorFailureBecomesInterruptedFailedWork(): void
    {
        $events = new \ArrayObject();
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch')));
        $error = new \RuntimeException('tool failed');
        $loop = new CollaborationLoop(
            primary: self::throwingCollaborator($error),
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
        $loop = new CollaborationLoop(
            primary: self::doneCollaborator('primary', new \ArrayObject()),
            reactors: [
                new class ($error) implements Reactor {
                    public function __construct(private \RuntimeException $error)
                    {
                    }

                    public function __invoke(CollabEvent $event, WorkContext $ctx): void
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
    public function blockedCollaboratorSuspendsThePlan(): void
    {
        $ctx = $this->ctx(WorkPlan::start(new WorkItem(Activity::Researching, 'Wait for token', id: 'work_wait')));
        $loop = new CollaborationLoop(
            primary: new class implements Collaborator {
                public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult
                {
                    return WorkResult::blocked($item->workItem->id, 'missing token');
                }

                public function supports(WorkPlanItem $item, WorkContext $ctx): bool
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
    private static function planRows(CollabStore $store): array
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
    private static function timelineRows(CollabStore $store): array
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
    private static function doneCollaborator(string $name, \ArrayObject $calls): Collaborator
    {
        return new class ($name, $calls) implements Collaborator {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(
                private string $name,
                private \ArrayObject $calls,
            ) {
            }

            public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult
            {
                $this->calls[] = $this->name . ':' . $item->workItem->id;

                return WorkResult::done($item->workItem->id, summary: 'done');
            }

            public function supports(WorkPlanItem $item, WorkContext $ctx): bool
            {
                return true;
            }
        };
    }

    /** @param \ArrayObject<int, string> $calls */
    private static function tagCollaborator(string $name, string $tag, \ArrayObject $calls): Collaborator
    {
        return new class ($name, $tag, $calls) implements Collaborator {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(
                private string $name,
                private string $tag,
                private \ArrayObject $calls,
            ) {
            }

            public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult
            {
                $this->calls[] = $this->name . ':' . $item->workItem->id;

                return WorkResult::done($item->workItem->id, summary: 'done');
            }

            public function supports(WorkPlanItem $item, WorkContext $ctx): bool
            {
                return in_array($this->tag, $item->workItem->tags, true);
            }
        };
    }

    /** @param \ArrayObject<int, string> $calls */
    private static function preferredCollaborator(string $name, string $agentId, \ArrayObject $calls): Collaborator
    {
        return new class ($name, $agentId, $calls) implements Collaborator {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(
                private string $name,
                private string $agentId,
                private \ArrayObject $calls,
            ) {
            }

            public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult
            {
                $this->calls[] = $this->name . ':' . $item->workItem->id;

                return WorkResult::done($item->workItem->id, summary: 'done');
            }

            public function supports(WorkPlanItem $item, WorkContext $ctx): bool
            {
                $preferred = $item->workItem->preferredParticipant;

                return $preferred === null || $preferred->equals(\Phalanx\Theatron\Collab\Messages\Address::agent($this->agentId));
            }
        };
    }

    private static function throwingCollaborator(\Throwable $error): Collaborator
    {
        return new class ($error) implements Collaborator {
            public function __construct(private \Throwable $error)
            {
            }

            public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult
            {
                throw $this->error;
            }

            public function supports(WorkPlanItem $item, WorkContext $ctx): bool
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

            public function __invoke(CollabEvent $event, WorkContext $ctx): void
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

            public function __invoke(CollabEvent $event, WorkContext $ctx): void
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

            public function __invoke(CollabEvent $event, WorkContext $ctx): void
            {
                if ($event->workResult !== null) {
                    $this->events[] = $event->kind;
                }
            }
        };
    }

    private function ctx(?WorkPlan $plan = null): WorkContext
    {
        $store = new CollabStore();
        if ($plan !== null) {
            $store->workPlan = new WorkPlanSlice($plan);
        }

        return new WorkContext($this->createStub(TaskScope::class), $store);
    }
}

class LoopEventLog
{
    /** @var list<CollabEvent> */
    private(set) array $events = [];

    public function record(CollabEvent $event): void
    {
        $this->events[] = $event;
    }
}
