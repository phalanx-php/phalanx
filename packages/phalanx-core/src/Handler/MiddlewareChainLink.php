<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * A single link in the middleware chain.
 *
 * Sets 'handler.next' attribute so middleware can call the next handler.
 */
final readonly class MiddlewareChainLink implements Executable
{
    public function __construct(
        private Scopeable|Executable $middleware,
        private Scopeable|Executable $next,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $scope = $scope->withAttribute('handler.next', $this->next);

        return $scope->execute($this->middleware);
    }
}
