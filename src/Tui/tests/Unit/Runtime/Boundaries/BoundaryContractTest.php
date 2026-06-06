<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Runtime\Boundaries;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Runtime\Boundaries\Inlet;
use Phalanx\Tui\Runtime\Boundaries\InletChannel;
use Phalanx\Tui\Runtime\Boundaries\InletMessage;
use Phalanx\Tui\Runtime\Boundaries\Outlet;
use Phalanx\Tui\Runtime\Boundaries\Urgency;
use Phalanx\Tui\Runtime\Events\Event;
use Phalanx\Tui\Runtime\Events\EventKind;
use Phalanx\Tui\Runtime\Events\RoutableEvent;
use Phalanx\Tui\Runtime\Messages\Envelope;
use Phalanx\Tui\Tests\Support\RecordingTaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoundaryContractTest extends TestCase
{
    #[Test]
    public function inletsEmitTypedMessagesIntoBoundaryChannel(): void
    {
        $channel = new RecordingInletChannel();
        $inlet = new PromptInlet();

        $inlet(new RecordingTaskScope(), $channel);

        self::assertSame('test-inlet', $inlet->name);
        self::assertCount(1, $channel->messages);
        self::assertSame('hello from inlet', $channel->messages[0]->envelope->payload);
        self::assertSame(Urgency::Prioritize, $channel->messages[0]->urgency);
    }

    #[Test]
    public function inletMessageAlwaysCarriesReceivedAtTimestamp(): void
    {
        $receivedAt = new \DateTimeImmutable('@123');
        $message = new InletMessage(Envelope::prompt('timestamped'), receivedAt: $receivedAt);

        self::assertSame($receivedAt, $message->receivedAt);
    }

    #[Test]
    public function outletsCanEarlyReturnForUnwantedEvents(): void
    {
        $outlet = new CompletionOnlyOutlet();
        $scope = new RecordingTaskScope();

        $outlet($scope, Event::record(EventKind::WorkReceived)->routable());
        $outlet($scope, Event::record(EventKind::WorkCompleted)->routable('done'));

        self::assertSame(['done'], $outlet->summaries);
    }
}

final class RecordingInletChannel implements InletChannel
{
    /** @var list<InletMessage> */
    private(set) array $messages = [];

    public function emit(InletMessage $message): void
    {
        $this->messages[] = $message;
    }
}

final class PromptInlet implements Inlet
{
    public string $name {
        get => 'test-inlet';
    }

    public function __invoke(TaskScope $scope, InletChannel $incoming): void
    {
        $incoming->emit(new InletMessage(Envelope::prompt('hello from inlet'), Urgency::Prioritize));
    }
}

final class CompletionOnlyOutlet implements Outlet
{
    /** @var list<string> */
    private(set) array $summaries = [];

    public function __invoke(TaskScope $scope, RoutableEvent $event): void
    {
        if ($event->kind !== EventKind::WorkCompleted) {
            return;
        }

        $this->summaries[] = $event->summary ?? '';
    }
}
