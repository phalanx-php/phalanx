<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use RuntimeException;

/**
 * Wraps a handler task with a non-empty middleware chain.
 *
 * Each middleware receives the current scope and a `$next` closure. The chain
 * is built once at construction and then invoked. Middleware dispatch is a
 * direct-call chain -- it does NOT
 * route through `$scope->execute()`, so trace/timeout/retry/Fiber-registry
 * behaviors apply only at the entry point (the HandlerGroup itself when the
 * runner invokes it).
 *
 * Construction precondition: `$middleware` MUST be non-empty. The empty case
 * is short-circuited by `HandlerGroup::executeHandler` before this class is
 * ever instantiated.
 */
final readonly class MiddlewareWrapper implements Executable
{
    /**
     * @param list<object> $middleware
     */
    public function __construct(
        private Scopeable|Executable $handler,
        private array $middleware,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $stack = $this->buildStack($this->handler, $this->middleware);

        return $stack($scope);
    }

    /**
     * @param list<object> $middleware
     * @return Closure(ExecutionScope): mixed
     */
    private function buildStack(Scopeable|Executable $handler, array $middleware): Closure
    {
        $next = static fn(ExecutionScope $scope): mixed => $handler($scope);

        foreach (array_reverse($middleware) as $mw) {
            if (!is_callable($mw)) {
                throw new RuntimeException($mw::class . ' middleware must be invokable.');
            }

            $middlewareCallable = Closure::fromCallable($mw);
            $current = $next;
            $next = static fn(ExecutionScope $scope): mixed => $middlewareCallable($scope, $current);
        }

        return $next;
    }
}
