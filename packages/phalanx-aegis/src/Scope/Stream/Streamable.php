<?php

declare(strict_types=1);

namespace Phalanx\Scope\Stream;

use Closure;
use Throwable;

/**
 * Hook plumbing for StreamSource implementations.
 *
 * Provides the canonical onStart / onEach / onError / onComplete / onDispose
 * registration surface plus the matching private fireOn* dispatchers. Using
 * classes call initStreamState() once during construction, register hooks via
 * the public methods, and invoke the private fireOn* helpers at the
 * corresponding lifecycle points inside their __invoke() body.
 *
 * Hook callbacks must be static closures. Capturing $this in a long-lived
 * stream pipeline creates reference cycles that survive across coroutines and
 * leak. PHPStan policy enforces this at call sites; the trait stores whatever
 * closure the caller hands in.
 */
trait Streamable
{
    /** @var list<Closure(StreamContext): void> */
    private array $onStartHooks = [];

    /** @var list<Closure(mixed, StreamContext): void> */
    private array $onEachHooks = [];

    /** @var list<Closure(Throwable, StreamContext): void> */
    private array $onErrorHooks = [];

    /** @var list<Closure(StreamContext): void> */
    private array $onCompleteHooks = [];

    /** @var list<Closure(StreamContext): void> */
    private array $onDisposeHooks = [];

    /** @param Closure(StreamContext): void $fn */
    public function onStart(Closure $fn): static
    {
        $this->onStartHooks[] = $fn;
        return $this;
    }

    /** @param Closure(mixed, StreamContext): void $fn */
    public function onEach(Closure $fn): static
    {
        $this->onEachHooks[] = $fn;
        return $this;
    }

    /** @param Closure(Throwable, StreamContext): void $fn */
    public function onError(Closure $fn): static
    {
        $this->onErrorHooks[] = $fn;
        return $this;
    }

    /** @param Closure(StreamContext): void $fn */
    public function onComplete(Closure $fn): static
    {
        $this->onCompleteHooks[] = $fn;
        return $this;
    }

    /** @param Closure(StreamContext): void $fn */
    public function onDispose(Closure $fn): static
    {
        $this->onDisposeHooks[] = $fn;
        return $this;
    }

    private function initStreamState(): void
    {
        $this->onStartHooks = [];
        $this->onEachHooks = [];
        $this->onErrorHooks = [];
        $this->onCompleteHooks = [];
        $this->onDisposeHooks = [];
    }

    private function fireOnStart(StreamContext $context): void
    {
        foreach ($this->onStartHooks as $hook) {
            $hook($context);
        }
    }

    private function fireOnEach(mixed $value, StreamContext $context): void
    {
        foreach ($this->onEachHooks as $hook) {
            $hook($value, $context);
        }
    }

    private function fireOnError(Throwable $error, StreamContext $context): void
    {
        foreach ($this->onErrorHooks as $hook) {
            $hook($error, $context);
        }
    }

    private function fireOnComplete(StreamContext $context): void
    {
        foreach ($this->onCompleteHooks as $hook) {
            $hook($context);
        }
    }

    private function fireOnDispose(StreamContext $context): void
    {
        foreach ($this->onDisposeHooks as $hook) {
            $hook($context);
        }
    }
}
