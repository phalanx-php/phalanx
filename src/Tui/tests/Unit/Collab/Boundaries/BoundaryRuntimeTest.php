<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Collab\Boundaries;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Collab\Apps\Runtime;
use Phalanx\Tui\Collab\Boundaries\BoundaryRunner;
use Phalanx\Tui\Collab\Boundaries\Inlet;
use Phalanx\Tui\Collab\Boundaries\InletChannel;
use Phalanx\Tui\Collab\Boundaries\InletMessage;
use Phalanx\Tui\Collab\Boundaries\InletQueue;
use Phalanx\Tui\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Tui\Collab\Boundaries\Outlet;
use Phalanx\Tui\Collab\Boundaries\OutletReactor;
use Phalanx\Tui\Collab\Boundaries\PromptInletMapper;
use Phalanx\Tui\Collab\Boundaries\Urgency;
use Phalanx\Tui\Collab\Events\EventKind;
use Phalanx\Tui\Collab\Events\RoutableEvent;
use Phalanx\Tui\Collab\Lifecycle\Loop;
use Phalanx\Tui\Collab\Messages\Address;
use Phalanx\Tui\Collab\Messages\Envelope;
use Phalanx\Tui\Collab\Messages\MessageKind;
use Phalanx\Tui\Collab\Participants\AgentParticipant;
use Phalanx\Tui\Collab\Plans\Activity;
use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkPlan;
use Phalanx\Tui\Collab\Plans\WorkPlanItem;
use Phalanx\Tui\Collab\Plans\WorkPlanStatus;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\State\Store;
use Phalanx\Tui\Collab\State\WorkPlanSlice;
use Phalanx\Tui\Collab\WorkContext;
use Phalanx\Tui\Tests\Support\RecordingTaskScope;
use Phalanx\Tui\Inputs\Key;
use Phalanx\Tui\Inputs\KeyEvent;
use Phalanx\Tui\Kit\InputComposer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoundaryRuntimeTest extends TestCase
{
    #[Test]
    public function inletQueueDrainsMessagesInFifoOrder(): void
    {
        $queue = new InletQueue();
        $first = new InletMessage(Envelope::prompt('first'));
        $second = new InletMessage(Envelope::prompt('second'));

        $queue->emit($first);
        $queue->emit($second);

        self::assertSame([$first, $second], $queue->drain());
        self::assertSame([], $queue->drain());
    }

    #[Test]
    public function promptMapperTurnsPromptMessagesIntoPrioritizedWork(): void
    {
        $to = Address::agent('reviewer');
        $envelope = Envelope::make(
            from: Address::user(),
            to: $to,
            kind: MessageKind::Prompt,
            payload: 'Review TC-6',
            priority: 10,
            tags: ['tc-6'],
        );

        $item = (new PromptInletMapper())(new InletMessage($envelope, Urgency::Interrupt));

        self::assertSame('Review TC-6', $item->prompt);
        self::assertSame(['tc-6'], $item->tags);
        self::assertSame($to, $item->preferredParticipant);
        self::assertSame(Urgency::Interrupt->priority(), $item->priority);
    }

    #[Test]
    public function promptMapperRejectsUnsupportedMessages(): void
    {
        $message = new InletMessage(Envelope::observation('daemon8', 'build finished'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only prompt messages');

        (new PromptInletMapper())($message);
    }

    #[Test]
    public function inputPromptSubmitterEmitsPromptEnvelopeIntoTheIncomingQueue(): void
    {
        $queue = new InletQueue();
        $submit = new InputPromptSubmitter($queue);

        $submit('Implement TC-6');

        $messages = $queue->drain();
        self::assertCount(1, $messages);
        self::assertSame(MessageKind::Prompt, $messages[0]->envelope->kind);
        self::assertSame('Implement TC-6', $messages[0]->envelope->payload);
        self::assertSame(Urgency::Queue, $messages[0]->urgency);
    }

    #[Test]
    public function inputPromptSubmitterIgnoresBlankPrompts(): void
    {
        $queue = new InletQueue();
        $submit = new InputPromptSubmitter($queue);

        $submit('   ');

        self::assertSame([], $queue->drain());
    }

    #[Test]
    public function boundaryRunnerInvokesInletsRecordsMessagesAndRunsTheLoop(): void
    {
        $store = new Store();
        $ctx = new WorkContext(new RecordingTaskScope(), $store);
        $calls = new \ArrayObject();
        $runner = new BoundaryRunner(
            loop: new Loop(primary: new DoneAgentParticipant($calls)),
            inlets: [new RuntimePromptInlet('Plan the next slice', Urgency::Prioritize)],
        );

        $status = $runner($ctx);

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(['Plan the next slice'], $calls->getArrayCopy());
        self::assertCount(1, $store->messages->envelopes);
        self::assertSame('Plan the next slice', $store->messages->envelopes[0]->payload);
        self::assertSame(Urgency::Prioritize->priority(), $ctx->plan->items()[0]->workItem->priority);
    }

    #[Test]
    public function silentInletsDoNotProjectPlaceholderLoopEvents(): void
    {
        $store = new Store();
        $ctx = new WorkContext(new RecordingTaskScope(), $store);
        $calls = new \ArrayObject();
        $runner = new BoundaryRunner(
            loop: new Loop(primary: new DoneAgentParticipant($calls)),
            inlets: [new SilentInlet()],
        );

        $status = $runner($ctx);

        self::assertSame(WorkPlanStatus::Active, $status);
        self::assertSame([], $calls->getArrayCopy());
        self::assertSame([], $store->messages->entries);
    }

    #[Test]
    public function agentHarnessRuntimeUsesTheCurrentTaskScopeOnEachTick(): void
    {
        $inlet = new ScopeRecordingInlet();
        $runtime = new Runtime(
            runner: new BoundaryRunner(
                loop: new Loop(primary: new DoneAgentParticipant(new \ArrayObject())),
                inlets: [$inlet],
            ),
            store: new Store(),
        );
        $first = new RecordingTaskScope();
        $second = new RecordingTaskScope();

        $runtime->tick($first);
        $runtime->tick($second);

        self::assertSame([$first, $second], $inlet->scopes);
    }

    #[Test]
    public function boundaryRunnerMapsBeforeRecordingUnsupportedMessages(): void
    {
        $store = new Store();
        $ctx = new WorkContext(new RecordingTaskScope(), $store);
        $runner = new BoundaryRunner(
            loop: new Loop(primary: new DoneAgentParticipant(new \ArrayObject())),
            inlets: [new UnsupportedInlet()],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only prompt messages');

        try {
            $runner($ctx);
        } finally {
            self::assertSame([], $store->messages->envelopes);
            self::assertSame([], $ctx->plan->items());
        }
    }

    #[Test]
    public function inputComposerSubmissionRunsThroughTheBoundaryRunner(): void
    {
        $queue = new InletQueue();
        $calls = new \ArrayObject();
        $store = new Store();
        $ctx = new WorkContext(new RecordingTaskScope(), $store);
        $submit = new InputPromptSubmitter($queue);
        $composer = InputComposer::empty(onSubmit: $submit);
        $runner = new BoundaryRunner(
            loop: new Loop(primary: new DoneAgentParticipant($calls)),
            incoming: $queue,
        );

        $composer->handleInput(new KeyEvent('s'));
        $composer->handleInput(new KeyEvent('h'));
        $composer->handleInput(new KeyEvent('i'));
        $composer->handleInput(new KeyEvent('p'));
        $composer->handleInput(new KeyEvent(Key::Enter));

        $status = $runner($ctx);

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(['ship'], $calls->getArrayCopy());
        self::assertSame('ship', $store->messages->envelopes[0]->payload);
    }

    #[Test]
    public function outletReactorRoutesLoopEventsToOutletsThroughTaskScope(): void
    {
        $scope = new RecordingTaskScope();
        $store = new Store();
        $ctx = new WorkContext($scope, $store);
        $outlet = new RecordingOutlet();
        $runner = new BoundaryRunner(
            loop: new Loop(
                primary: new DoneAgentParticipant(new \ArrayObject()),
                reactors: [new OutletReactor([$outlet])],
            ),
            inlets: [new RuntimePromptInlet('Route events')],
        );

        $runner($ctx);

        self::assertContains(EventKind::WorkReceived, $outlet->events);
        self::assertContains(EventKind::WorkCompleted, $outlet->events);
        self::assertSame($scope, $outlet->scope);

        $received = array_find(
            $outlet->routableEvents,
            static fn (RoutableEvent $event): bool => $event->kind === EventKind::WorkReceived,
        );

        self::assertInstanceOf(RoutableEvent::class, $received);
        self::assertSame('Route events', $received->envelope?->payload);
        self::assertSame('Route events', $received->workItem?->prompt);
    }

    #[Test]
    public function preseededReadyWorkStillRunsAndRoutesOutletEventsWithoutInput(): void
    {
        $scope = new RecordingTaskScope();
        $store = new Store();
        $store->workPlan = new WorkPlanSlice(WorkPlan::start(new WorkItem(
            Activity::Testing,
            'Route preseeded work',
            id: 'work_preseeded_outlet',
        )));
        $outlet = new RecordingOutlet();
        $runner = new BoundaryRunner(
            loop: new Loop(
                primary: new DoneAgentParticipant(new \ArrayObject()),
                reactors: [new OutletReactor([$outlet])],
            ),
        );

        $status = $runner(new WorkContext($scope, $store));

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(WorkPlanStatus::Complete, $store->workPlan->plan->status);
        self::assertContains(EventKind::WorkItemStarted, $outlet->events);
        self::assertContains(EventKind::WorkItemCompleted, $outlet->events);
        self::assertSame($scope, $outlet->scope);

        $received = array_find(
            $outlet->routableEvents,
            static fn (RoutableEvent $event): bool => $event->kind === EventKind::WorkReceived,
        );

        self::assertInstanceOf(RoutableEvent::class, $received);
        self::assertNull($received->envelope);
        self::assertNull($received->workItem);
    }
}

final class RuntimePromptInlet implements Inlet
{
    public string $name {
        get => 'prompt';
    }

    public function __construct(
        private string $prompt,
        private Urgency $urgency = Urgency::Queue,
    ) {
    }

    public function __invoke(TaskScope $scope, InletChannel $incoming): void
    {
        $incoming->emit(new InletMessage(Envelope::prompt($this->prompt), $this->urgency));
    }
}

final class UnsupportedInlet implements Inlet
{
    public string $name {
        get => 'unsupported';
    }

    public function __invoke(TaskScope $scope, InletChannel $incoming): void
    {
        $incoming->emit(new InletMessage(Envelope::observation('daemon8', 'build finished')));
    }
}

final class SilentInlet implements Inlet
{
    public string $name {
        get => 'silent';
    }

    public function __invoke(TaskScope $scope, InletChannel $incoming): void
    {
    }
}

final class ScopeRecordingInlet implements Inlet
{
    public string $name {
        get => 'scope-recorder';
    }

    /** @var list<TaskScope> */
    private(set) array $scopes = [];

    public function __invoke(TaskScope $scope, InletChannel $incoming): void
    {
        $this->scopes[] = $scope;
    }
}

final class DoneAgentParticipant implements AgentParticipant
{
    public function __construct(
        /** @var \ArrayObject<int, string> */
        private \ArrayObject $calls,
    ) {
    }

    public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult
    {
        $this->calls[] = $item->workItem->prompt;

        return WorkResult::done($item->workItem->id);
    }

    public function supports(WorkPlanItem $item, WorkContext $ctx): bool
    {
        return true;
    }
}

final class RecordingOutlet implements Outlet
{
    /** @var list<EventKind> */
    private(set) array $events = [];

    /** @var list<RoutableEvent> */
    private(set) array $routableEvents = [];

    private(set) ?TaskScope $scope = null;

    public function __invoke(RoutableEvent $event, TaskScope $scope): void
    {
        $this->events[] = $event->kind;
        $this->routableEvents[] = $event;
        $this->scope = $scope;
    }
}
