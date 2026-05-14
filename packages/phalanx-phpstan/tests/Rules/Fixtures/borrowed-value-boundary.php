<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Pool\BorrowedValue;
use Phalanx\Styx\Channel;

final class BorrowedAgentEvent implements BorrowedValue
{
}

final class BorrowedValueBoundaryFixture
{
    private static ?BorrowedAgentEvent $staticStored = null;

    private ?BorrowedAgentEvent $stored = null;

    /** @var list<BorrowedAgentEvent> */
    private array $events = [];

    private ?\Closure $storedClosure = null;

    public function invalidChannelEmit(Channel $channel, BorrowedAgentEvent $event): void
    {
        $channel->emit($event);
        $channel->tryEmit(['event' => $event]);
    }

    public function invalidChannelEmitArrayVariable(Channel $channel, BorrowedAgentEvent $event): void
    {
        $events = [$event];

        $channel->emit($events);
    }

    public function invalidChannelEmitClosure(Channel $channel, BorrowedAgentEvent $event): void
    {
        $channel->emit(static function () use ($event): void {
            $event::class;
        });
    }

    public function invalidReturn(BorrowedAgentEvent $event): BorrowedAgentEvent
    {
        return $event;
    }

    /** @return list<BorrowedAgentEvent> */
    public function invalidReturnArray(BorrowedAgentEvent $event): array
    {
        return [$event];
    }

    /** @return list<BorrowedAgentEvent> */
    public function invalidReturnArrayVariable(BorrowedAgentEvent $event): array
    {
        $events = [$event];

        return $events;
    }

    public function invalidArrowReturn(BorrowedAgentEvent $event): \Closure
    {
        return static fn(): BorrowedAgentEvent => $event;
    }

    public function invalidArrowArrayReturn(BorrowedAgentEvent $event): \Closure
    {
        return static fn(): array => [$event];
    }

    public function invalidClosureReturn(BorrowedAgentEvent $event): \Closure
    {
        return static function () use ($event): void {
            $event::class;
        };
    }

    public function invalidPropertyStore(BorrowedAgentEvent $event): void
    {
        $this->stored = $event;
        self::$staticStored = $event;
    }

    public function invalidPropertyArrayStore(BorrowedAgentEvent $event): void
    {
        $events = [$event];

        $this->events = $events;
    }

    public function invalidPropertyClosureStore(BorrowedAgentEvent $event): void
    {
        $this->storedClosure = static function () use ($event): void {
            $event::class;
        };
    }

    public function validLocalUse(BorrowedAgentEvent $event): string
    {
        return $event::class;
    }

    public function validLocalArrayUse(BorrowedAgentEvent $event): int
    {
        $events = [$event];

        return count($events);
    }
}

final class BorrowedValuePromotedPropertyFixture
{
    public function __construct(
        private BorrowedAgentEvent $event,
    ) {
    }
}
