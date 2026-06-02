<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Contract;

use Closure;
use Phalanx\Stoa\RequestContext;

/**
 * Stoa middleware receives the current request context and a callable for the
 * next link in the chain. It may pass through, wrap, replace, or short-circuit
 * the result.
 */
interface Middleware
{
    /**
     * @param Closure(RequestContext): mixed $next
     */
    public function __invoke(RequestContext $ctx, Closure $next): mixed;
}
