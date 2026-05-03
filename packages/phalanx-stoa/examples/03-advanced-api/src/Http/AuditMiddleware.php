<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced\Http;

use Acme\StoaDemo\Advanced\Services\AuditLog;
use Closure;
use Phalanx\Stoa\Contract\Middleware;
use Phalanx\Stoa\RequestScope;

final class AuditMiddleware implements Middleware
{
    public function __construct(private readonly AuditLog $audit)
    {
    }

    public function __invoke(RequestScope $scope, Closure $next): mixed
    {
        $this->audit->record($scope->method(), $scope->path());

        return $next($scope);
    }
}
