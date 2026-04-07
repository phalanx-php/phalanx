<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Closure;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Wraps a resolved handler instance plus an invoker closure into a single
 * Scopeable so the middleware chain can treat it like any other task.
 *
 * The invoker is responsible for translating the scope-only invocation into
 * whatever shape the underlying handler expects -- e.g. an HTTP route may
 * call InputHydrator to produce additional DTO arguments before applying
 * them to the instance.
 *
 * INTERNAL: this class is constructed only from HandlerGroup::executeHandler
 * and is always called with an ExecutionScope (the dispatch path that
 * reaches the middleware chain). It implements both Scopeable and Executable
 * so the middleware chain's `Scopeable|Executable` union accepts it; the
 * dual implementation is a type-system convenience, not a semantic claim.
 */
final readonly class HandlerInvocationAdapter implements Scopeable, Executable
{
    /**
     * @param Closure(Scopeable|Executable, ExecutionScope): mixed $invoker
     */
    public function __construct(
        private Scopeable|Executable $instance,
        private Closure $invoker,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        return ($this->invoker)($this->instance, $scope);
    }
}
