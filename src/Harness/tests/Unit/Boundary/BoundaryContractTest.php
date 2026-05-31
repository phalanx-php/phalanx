<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Boundary;

use Phalanx\Harness\Boundary\Inlet;
use Phalanx\Harness\Boundary\InletChannel;
use Phalanx\Harness\Boundary\InletMessage;
use Phalanx\Harness\Boundary\Outlet;
use Phalanx\Harness\Boundary\Urgency;
use Phalanx\Harness\Event\EventKind;
use Phalanx\Harness\Event\HarnessEvent;
use Phalanx\Harness\Event\RoutableEvent;
use Phalanx\Harness\Message\Envelope;
use Phalanx\Harness\Tests\Support\RecordingTaskScope;
use Phalanx\Scope\TaskScope;
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
    public function outletsCanEarlyReturnForUnwantedEvents(): void
    {
        $outlet = new CompletionOnlyOutlet();
        $scope = new RecordingTaskScope();

        $outlet(HarnessEvent::record(EventKind::WorkReceived)->routable(), $scope);
        $outlet(HarnessEvent::record(EventKind::WorkCompleted)->routable('done'), $scope);

        self::assertSame(['done'], $outlet->summaries);
    }
}

final class RecordingInletChannel implements InletChannel
{
    /** @var list<InletMessage> */
    public array $messages = [];

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
    public array $summaries = [];

    public function __invoke(RoutableEvent $event, TaskScope $scope): void
    {
        if ($event->kind !== EventKind::WorkCompleted) {
            return;
        }

        $this->summaries[] = $event->summary ?? '';
    }
}
