<?php

declare(strict_types=1);

namespace Phalanx\Tui\Reactive;

use Closure;
use Phalanx\Scope\Scope;
use ReflectionFunction;
use RuntimeException;

final class Signal
{
    private int $nextSubscriberId = 0;

    /** @var array<int, Closure(): void> */
    private array $subscribers = [];

    public int $subscriberCount {
        get => count($this->subscribers);
    }

    private(set) bool $isDisposed = false;

    public function __construct(private mixed $storedValue)
    {
    }

    public function get(): mixed
    {
        Tracker::recordAccess($this);

        return $this->storedValue;
    }

    public function set(mixed $value): void
    {
        $this->write($value);
    }

    /** @param Closure(mixed): mixed $updater */
    public function update(Closure $updater): void
    {
        $this->guardStaticUpdater($updater);
        $this->write($updater($this->storedValue));
    }

    /** @param Closure(Scope, mixed): mixed $updater */
    public function updateIn(Scope $scope, Closure $updater): void
    {
        $this->guardStaticUpdater($updater);
        $this->write($updater($scope, $this->storedValue));
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

    private function write(mixed $value): void
    {
        if ($this->isDisposed) {
            throw new RuntimeException('Cannot write to a disposed signal.');
        }

        if ($value === $this->storedValue) {
            return;
        }

        $this->storedValue = $value;
        $this->notify();
    }

    private function guardStaticUpdater(Closure $updater): void
    {
        if (!new ReflectionFunction($updater)->isStatic()) {
            throw new RuntimeException('Signal updater closures must be static closures.');
        }
    }

    private function notify(): void
    {
        $snapshot = $this->subscribers;

        foreach ($snapshot as $subscriber) {
            $subscriber();
        }
    }
}
