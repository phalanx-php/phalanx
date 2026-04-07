<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Closure;
use LogicException;
use Phalanx\ExecutionScope;
use Phalanx\Scope;
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

    public function __invoke(Scope $scope): mixed
    {
        if (!$scope instanceof ExecutionScope) {
            throw new LogicException(
                'HandlerInvocationAdapter requires an ExecutionScope (got '
                . $scope::class . '). The adapter is only used inside the '
                . 'middleware dispatch path which always carries an ExecutionScope.'
            );
        }

        return ($this->invoker)($this->instance, $scope);
    }
}
