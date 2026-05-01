<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Wraps a handler task with a non-empty middleware chain.
 *
 * Each middleware is a Scopeable|Executable that calls the next handler via
 * `$scope->attribute('handler.next')`. The chain is built once at construction
 * and then invoked. Middleware dispatch is a direct-call chain -- it does NOT
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
     * @param list<Scopeable|Executable> $middleware
     */
    public function __construct(
        private Scopeable|Executable $handler,
        private array $middleware,
    ) {
    }

    /**
     * @param list<Scopeable|Executable> $middleware
     */
    private function buildStack(Scopeable|Executable $handler, array $middleware): Scopeable|Executable
    {
        $next = $handler;

        foreach (array_reverse($middleware) as $mw) {
            $next = new MiddlewareChainLink($mw, $next);
        }

        return $next;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $stack = $this->buildStack($this->handler, $this->middleware);

        return ($stack)($scope);
    }
}
