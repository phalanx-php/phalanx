<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactive;

use Closure;
use ReflectionFunction;
use RuntimeException;
use WeakReference;

final class Watch
{
    private bool $disposed = false;
    private bool $running = false;
    private mixed $lastValue;

    /** @var list<SignalSubscription|ComputedSubscription> */
    private array $depSubscriptions = [];

    public function __construct(
        private readonly Closure $selector,
        private readonly Closure $effect,
    ) {
        if (!new ReflectionFunction($selector)->isStatic()) {
            throw new RuntimeException('Watch selector must be a static closure.');
        }

        if (!new ReflectionFunction($effect)->isStatic()) {
            throw new RuntimeException('Watch effect must be a static closure.');
        }

        $this->lastValue = $this->evaluate();
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;

        foreach ($this->depSubscriptions as $sub) {
            $sub->dispose();
        }

        $this->depSubscriptions = [];
    }

    private function evaluate(): mixed
    {
        foreach ($this->depSubscriptions as $sub) {
            $sub->dispose();
        }

        $this->depSubscriptions = [];

        $frame = Tracker::push();

        try {
            $value = ($this->selector)();
        } catch (\Throwable $e) {
            Tracker::pop($frame);
            $this->dispose();

            throw $e;
        }

        $deps = Tracker::pop($frame);

        $weakSelf = WeakReference::create($this);

        foreach ($deps as $dep) {
            if ($dep instanceof Signal) {
                $this->depSubscriptions[] = $dep->subscribe(
                    static function () use ($weakSelf): void {
                        $weakSelf->get()?->check();
                    },
                );
            } elseif ($dep instanceof Computed) {
                $this->depSubscriptions[] = $dep->subscribe(
                    static function () use ($weakSelf): void {
                        $weakSelf->get()?->check();
                    },
                );
            }
        }

        return $value;
    }

    private function check(): void
    {
        if ($this->disposed || $this->running) {
            return;
        }

        $this->running = true;

        try {
            $new = $this->evaluate();
            $old = $this->lastValue;

            if ($new === $old) {
                return;
            }

            $this->lastValue = $new;
            ($this->effect)($new, $old);
        } finally {
            $this->running = false;
        }
    }
}
