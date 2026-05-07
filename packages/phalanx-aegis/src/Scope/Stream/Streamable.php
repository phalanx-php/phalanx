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

    /** @var list<Closure(mixed, ExecutionScope): void> */
    private array $onEachHooks = [];

    /** @var list<Closure(Throwable, ExecutionScope): void> */
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

    /** @param Closure(mixed, ExecutionScope): void $fn */
    public function onEach(Closure $fn): static
    {
        $this->onEachHooks[] = $fn;
        return $this;
    }

    /** @param Closure(Throwable, ExecutionScope): void $fn */
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

    private function fireOnEach(mixed $value, ExecutionScope $scope): void
    {
        foreach ($this->onEachHooks as $hook) {
            $hook($value, $scope);
        }
    }

    private function fireOnError(Throwable $error, ExecutionScope $scope): void
    {
        foreach ($this->onErrorHooks as $hook) {
            $hook($error, $scope);
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
