<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Boundaries;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Collab\Apps\CollabRuntime;
use Phalanx\Theatron\Collab\Boundaries\BoundaryRunner;
use Phalanx\Theatron\Collab\Boundaries\Inlet;
use Phalanx\Theatron\Collab\Boundaries\InletChannel;
use Phalanx\Theatron\Collab\Boundaries\InletMessage;
use Phalanx\Theatron\Collab\Boundaries\InletQueue;
use Phalanx\Theatron\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Theatron\Collab\Boundaries\Outlet;
use Phalanx\Theatron\Collab\Boundaries\OutletReactor;
use Phalanx\Theatron\Collab\Boundaries\PromptInletMapper;
use Phalanx\Theatron\Collab\Boundaries\Urgency;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Events\RoutableEvent;
use Phalanx\Theatron\Collab\Lifecycle\CollaborationLoop;
use Phalanx\Theatron\Collab\Messages\Address;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Messages\MessageKind;
use Phalanx\Theatron\Collab\Participants\Collaborator;
use Phalanx\Theatron\Collab\Plans\WorkPlanItem;
use Phalanx\Theatron\Collab\Plans\WorkPlanStatus;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\WorkContext;
use Phalanx\Theatron\Tests\Support\RecordingTaskScope;
use Phalanx\Theatron\Tui\Inputs\Key;
use Phalanx\Theatron\Tui\Inputs\KeyEvent;
use Phalanx\Theatron\Tui\Kit\InputComposer;
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
        $store = new CollabStore();
        $ctx = new WorkContext(new RecordingTaskScope(), $store);
        $calls = new \ArrayObject();
        $runner = new BoundaryRunner(
            loop: new CollaborationLoop(primary: new DoneCollaborator($calls)),
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
        $store = new CollabStore();
        $ctx = new WorkContext(new RecordingTaskScope(), $store);
        $calls = new \ArrayObject();
        $runner = new BoundaryRunner(
            loop: new CollaborationLoop(primary: new DoneCollaborator($calls)),
            inlets: [new SilentInlet()],
        );

        $status = $runner($ctx);

        self::assertSame(WorkPlanStatus::Active, $status);
        self::assertSame([], $calls->getArrayCopy());
        self::assertSame([], $store->messages->entries);
    }

    #[Test]
    public function collabRuntimeUsesTheCurrentTaskScopeOnEachTick(): void
    {
        $inlet = new ScopeRecordingInlet();
        $runtime = new CollabRuntime(
            runner: new BoundaryRunner(
                loop: new CollaborationLoop(primary: new DoneCollaborator(new \ArrayObject())),
                inlets: [$inlet],
            ),
            store: new CollabStore(),
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
        $store = new CollabStore();
        $ctx = new WorkContext(new RecordingTaskScope(), $store);
        $runner = new BoundaryRunner(
            loop: new CollaborationLoop(primary: new DoneCollaborator(new \ArrayObject())),
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
        $store = new CollabStore();
        $ctx = new WorkContext(new RecordingTaskScope(), $store);
        $submit = new InputPromptSubmitter($queue);
        $composer = InputComposer::empty(onSubmit: $submit);
        $runner = new BoundaryRunner(
            loop: new CollaborationLoop(primary: new DoneCollaborator($calls)),
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
        $store = new CollabStore();
        $ctx = new WorkContext($scope, $store);
        $outlet = new RecordingOutlet();
        $runner = new BoundaryRunner(
            loop: new CollaborationLoop(
                primary: new DoneCollaborator(new \ArrayObject()),
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

final class DoneCollaborator implements Collaborator
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
