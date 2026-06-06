<?php

declare(strict_types=1);

namespace Phalanx\Middleware;

use Closure;
use Phalanx\Scope\ExecutionScope;

interface ServiceTransformationMiddleware
{
    /** @param Closure(): object $next */
    public function transform(ExecutionScope $scope, string $type, Closure $next): object;
}
