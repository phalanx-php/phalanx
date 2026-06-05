<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Reactive;

use Closure;
use ReflectionFunction;
use RuntimeException;
use WeakReference;

final class Computed
{
    public mixed $value {
        get {
            if ($this->dirty) {
        $this->recompute();
            }

            Tracker::recordAccess($this);

            return $this->storedValue;
        }
    }

    public int $subscriberCount {
        get => count($this->subscribers);
    }

    private bool $dirty = true;
    private bool $computing = false;
    private(set) bool $isDisposed = false;

    private int $nextSubscriberId = 0;

    private mixed $storedValue = null;

    /** @var array<int, Closure(): void> */
    private array $subscribers = [];

    /** @var list<SignalSubscription|ComputedSubscription|ResourceSubscription> */
    private array $depSubscriptions = [];

    public function __construct(
        private Closure $factory,
        private ?Closure $onDirty = null,
    ) {
        if (!new ReflectionFunction($factory)->isStatic()) {
            throw new RuntimeException('Computed factory must be a static closure.');
        }

        if ($onDirty !== null && !new ReflectionFunction($onDirty)->isStatic()) {
            throw new RuntimeException('Computed onDirty callback must be a static closure.');
        }
    }

    public function subscribe(Closure $subscriber): ComputedSubscription
    {
        if ($this->isDisposed) {
            throw new RuntimeException('Cannot subscribe to a disposed computed.');
        }

        if (!new ReflectionFunction($subscriber)->isStatic()) {
            throw new RuntimeException('Computed subscribers must be static closures.');
        }

        $id = $this->nextSubscriberId++;
        $this->subscribers[$id] = $subscriber;

        return new ComputedSubscription($this, $id);
    }

    public function unsubscribe(int $id): void
    {
        unset($this->subscribers[$id]);
    }

    public function markDirty(): void
    {
        if ($this->dirty || $this->isDisposed) {
            return;
        }

        $this->dirty = true;
        $this->onDirty?->__invoke();

        foreach ($this->subscribers as $subscriber) {
            $subscriber();
        }
    }

    public function dispose(): void
    {
        if ($this->isDisposed) {
            return;
        }

        $this->isDisposed = true;

        foreach ($this->depSubscriptions as $sub) {
            $sub->dispose();
        }

        $this->depSubscriptions = [];
        $this->subscribers = [];
    }

    private function recompute(): void
    {
        if ($this->computing) {
            throw new RuntimeException('Circular computed dependency detected.');
        }

        $this->computing = true;

        foreach ($this->depSubscriptions as $sub) {
            $sub->dispose();
        }

        $this->depSubscriptions = [];

        $frame = Tracker::push();

        try {
            $this->storedValue = ($this->factory)();
        } finally {
            $deps = Tracker::pop($frame);
            $this->computing = false;
        }

        $weakSelf = WeakReference::create($this);

        foreach ($deps as $dep) {
            if ($dep instanceof Signal) {
                $this->depSubscriptions[] = $dep->subscribe(
                    static function () use ($weakSelf): void {
                        $weakSelf->get()?->markDirty();
                    },
                );
            } elseif ($dep instanceof self) {
                $this->depSubscriptions[] = $dep->subscribe(
                    static function () use ($weakSelf): void {
                        $weakSelf->get()?->markDirty();
                    },
                );
            } elseif ($dep instanceof Resource) {
                $this->depSubscriptions[] = $dep->subscribe(
                    static function () use ($weakSelf): void {
                        $weakSelf->get()?->markDirty();
                    },
                );
            }
        }

        $this->dirty = false;
    }
}
