<?php

declare(strict_types=1);

namespace Phalanx\Scope\Stream;

use Closure;
use Phalanx\Scope\ExecutionScope;
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
    /** @var list<Closure(ExecutionScope): void> */
    private array $onStartHooks = [];

    /** @var list<Closure(ExecutionScope, mixed): void> */
    private array $onEachHooks = [];

    /** @var list<Closure(ExecutionScope, Throwable): void> */
    private array $onErrorHooks = [];

    /** @var list<Closure(ExecutionScope): void> */
    private array $onCompleteHooks = [];

    /** @var list<Closure(ExecutionScope): void> */
    private array $onDisposeHooks = [];

    /** @param Closure(ExecutionScope): void $fn */
    public function onStart(Closure $fn): static
    {
        $this->onStartHooks[] = $fn;

        return $this;
    }

    /** @param Closure(ExecutionScope, mixed): void $fn */
    public function onEach(Closure $fn): static
    {
        $this->onEachHooks[] = $fn;

        return $this;
    }

    /** @param Closure(ExecutionScope, Throwable): void $fn */
    public function onError(Closure $fn): static
    {
        $this->onErrorHooks[] = $fn;

        return $this;
    }

    /** @param Closure(ExecutionScope): void $fn */
    public function onComplete(Closure $fn): static
    {
        $this->onCompleteHooks[] = $fn;

        return $this;
    }

    /** @param Closure(ExecutionScope): void $fn */
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

    private function fireOnStart(ExecutionScope $scope): void
    {
        foreach ($this->onStartHooks as $hook) {
            $hook($scope);
        }
    }

    private function fireOnEach(ExecutionScope $scope, mixed $value): void
    {
        foreach ($this->onEachHooks as $hook) {
            $hook($scope, $value);
        }
    }

    private function fireOnError(ExecutionScope $scope, Throwable $error): void
    {
        foreach ($this->onErrorHooks as $hook) {
            $hook($scope, $error);
        }
    }

    private function fireOnComplete(ExecutionScope $scope): void
    {
        foreach ($this->onCompleteHooks as $hook) {
            $hook($scope);
        }
    }

    private function fireOnDispose(ExecutionScope $scope): void
    {
        foreach ($this->onDisposeHooks as $hook) {
            $hook($scope);
        }
    }
}
