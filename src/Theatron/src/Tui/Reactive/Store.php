<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Reactive;

use Closure;
use Phalanx\Theatron\Tui\Reactive\Tracker;
use ReflectionFunction;
use RuntimeException;

abstract class Store
{
    private int $nextSubscriberId = 0;

    /** @var array<int, Closure(): void> */
    private array $subscribers = [];

    /** @var array<class-string, object> */
    private array $slices = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param Closure(T): T $transform
     * @return T
     */
    public function mutate(string $class, Closure $transform): object
    {
        /** @var T $current */
        $current = $this->read($class);
        /** @var T $next */
        $next = $transform($current);
        $this->write($class, $next);

        return $next;
    }

    public function subscribe(Closure $subscriber): StoreSubscription
    {
        if (!new ReflectionFunction($subscriber)->isStatic()) {
            throw new RuntimeException('Store subscribers must be static closures.');
        }

        $id = $this->nextSubscriberId++;
        $this->subscribers[$id] = $subscriber;

        return new StoreSubscription($this, $id);
    }

    public function unsubscribe(int $id): void
    {
        unset($this->subscribers[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param T $initial
     */
    protected function register(string $class, object $initial): void
    {
        if (isset($this->slices[$class])) {
            throw new RuntimeException(sprintf('Slice %s is already registered.', $class));
        }

        $this->slices[$class] = $initial;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function read(string $class): object
    {
        Tracker::recordAccess($this);

        if (!isset($this->slices[$class])) {
            throw new RuntimeException(sprintf('Slice %s is not registered.', $class));
        }

        /** @var T */
        return $this->slices[$class];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param T $value
     */
    protected function write(string $class, object $value): void
    {
        if (!isset($this->slices[$class])) {
            throw new RuntimeException(sprintf('Slice %s is not registered.', $class));
        }

        if (!$value instanceof $class) {
            throw new RuntimeException(sprintf(
                'Expected instance of %s, got %s.',
                $class,
                $value::class,
            ));
        }

        $this->slices[$class] = $value;
        $this->notify();
    }

    private function notify(): void
    {
        $snapshot = $this->subscribers;

        foreach ($snapshot as $subscriber) {
            $subscriber();
        }
    }
}
