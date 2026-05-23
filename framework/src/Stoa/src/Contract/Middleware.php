<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Contract;

use Closure;
use Phalanx\Stoa\RequestScope;

/**
 * Stoa middleware receives the current request scope and a callable for the
 * next link in the chain. It may pass through, wrap, replace, or short-circuit
 * the result.
 */
interface Middleware
{
    /**
     * @param Closure(RequestScope): mixed $next
     */
    public function __invoke(RequestScope $scope, Closure $next): mixed;
}
