<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Contract;

use Closure;
use Phalanx\Stoa\RequestScope;

/**
 * Implementors must also implement Executable with __invoke(RequestScope)
 * that reads 'handler.next' from scope attributes and delegates to handle().
 * See Authenticate for the canonical pattern.
 */
interface Middleware
{
    /**
     * @param Closure(RequestScope): mixed $next
     */
    public function handle(RequestScope $scope, Closure $next): mixed;
}
