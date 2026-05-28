<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactive;

use Closure;
use ReflectionFunction;
use RuntimeException;

final class Signal
{
    private int $nextSubscriberId = 0;

    /** @var array<int, Closure(): void> */
    private array $subscribers = [];

    public mixed $value {
        get {
            Tracker::recordAccess($this);

            return $this->storedValue;
        }
        set(mixed $value) {
            if ($this->isDisposed) {
                throw new RuntimeException('Cannot write to a disposed signal.');
            }

            if ($value === $this->storedValue) {
                return;
            }

            $this->storedValue = $value;
            $this->notify();
        }
    }

    public int $subscriberCount {
        get => count($this->subscribers);
    }

    private(set) bool $isDisposed = false;

    public function __construct(private mixed $storedValue)
    {
    }

    public function subscribe(Closure $subscriber): SignalSubscription
    {
        if ($this->isDisposed) {
            throw new RuntimeException('Cannot subscribe to a disposed signal.');
        }

        if (!new ReflectionFunction($subscriber)->isStatic()) {
            throw new RuntimeException('Signal subscribers must be static closures.');
        }

        $id = $this->nextSubscriberId++;
        $this->subscribers[$id] = $subscriber;

        return new SignalSubscription($this, $id);
    }

    public function unsubscribe(int $id): void
    {
        unset($this->subscribers[$id]);
    }

    public function dispose(): void
    {
        $this->subscribers = [];
        $this->isDisposed = true;
    }

    private function notify(): void
    {
        foreach ($this->subscribers as $subscriber) {
            $subscriber();
        }
    }
}
