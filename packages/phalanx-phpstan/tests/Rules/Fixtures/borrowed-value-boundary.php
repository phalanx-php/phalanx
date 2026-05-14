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

    /** @var list<BorrowedAgentEvent> */
    private static array $staticEvents = [];

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

    public function invalidClosureVariableReturn(BorrowedAgentEvent $event): \Closure
    {
        $fn = static function () use ($event): void {
            $event::class;
        };

        return $fn;
    }

    public function invalidArrowClosureCaptureReturn(BorrowedAgentEvent $event): \Closure
    {
        return static fn(): string => $event::class;
    }

    public function invalidClosureVariableChannelEmit(Channel $channel, BorrowedAgentEvent $event): void
    {
        $fn = static function () use ($event): void {
            $event::class;
        };

        $channel->emit($fn);
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

    public function invalidPropertyClosureVariableStore(BorrowedAgentEvent $event): void
    {
        $fn = static function () use ($event): void {
            $event::class;
        };

        $this->storedClosure = $fn;
    }

    public function invalidPropertyArrayAppend(BorrowedAgentEvent $event): void
    {
        $this->events[] = $event;
        self::$staticEvents[] = $event;
        $this->events += [$event];
    }

    public function invalidYield(BorrowedAgentEvent $event): \Generator
    {
        yield $event;
        yield from [$event];
    }

    public function invalidClosureVariableYield(BorrowedAgentEvent $event): \Generator
    {
        $fn = static function () use ($event): void {
            $event::class;
        };

        yield $fn;
    }

    public function invalidArrowVariablePropertyStore(BorrowedAgentEvent $event): void
    {
        $fn = static fn(): string => $event::class;

        $this->storedClosure = $fn;
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

    public function validLocalClosureUse(BorrowedAgentEvent $event): void
    {
        $fn = static function () use ($event): string {
            return $event::class;
        };

        $fn();
    }

    public function validLocalArrowUse(BorrowedAgentEvent $event): void
    {
        $fn = static fn(): string => $event::class;

        $fn();
    }

    public function validClosureVariableReassignment(BorrowedAgentEvent $event): \Closure
    {
        $fn = static function () use ($event): void {
            $event::class;
        };
        $fn = static function (): void {
        };

        return $fn;
    }
}

final class BorrowedValuePromotedPropertyFixture
{
    public function __construct(
        private BorrowedAgentEvent $event,
    ) {
    }
}
