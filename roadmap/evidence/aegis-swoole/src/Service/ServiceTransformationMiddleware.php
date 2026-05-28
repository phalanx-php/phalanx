<?php

declare(strict_types=1);

namespace AegisSwoole\Service;

use AegisSwoole\Scope\Scope;
use Closure;

interface ServiceTransformationMiddleware
{
    /**
     * Wrap the resolution of a service. Call $next() to invoke the next layer of
     * middleware (or the actual factory at the bottom of the chain). May return
     * the resolved instance unchanged, decorate it, or replace it entirely.
     *
     * `$type` is the resolved class-string the container is materializing (post-alias).
     * It's typed as `string` rather than `class-string` because the container's lookup
     * path normalizes through `ServiceGraph::alias()` which returns `string`.
     *
     * @param Closure(): object $next
     */
    public function transform(string $type, Closure $next, Scope $scope): object;
}
