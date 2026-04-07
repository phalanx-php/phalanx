<?php

declare(strict_types=1);

namespace Phalanx\Http\Contract;

use Closure;
use Phalanx\Http\RequestScope;

/**
 * HTTP middleware with a typed $next closure.
 *
 * Preferred shape for HTTP middleware in Phalanx. The $next closure receives
 * a RequestScope (or subtype) and returns the inner handler's result. The
 * middleware wraps the call to add pre/post logic.
 *
 * The existing Executable-based middleware (using $scope->attribute('handler.next'))
 * remains a valid alternative but is no longer the preferred pattern.
 */
interface Middleware
{
    /**
     * @param Closure(RequestScope): mixed $next
     */
    public function handle(RequestScope $scope, Closure $next): mixed;
}
