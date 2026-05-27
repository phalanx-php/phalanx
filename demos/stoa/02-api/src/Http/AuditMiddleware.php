<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Api\Http;

use Acme\StoaDemo\Api\Services\AuditLog;
use Closure;
use Phalanx\Stoa\Contract\Middleware;
use Phalanx\Stoa\RequestContext;

final class AuditMiddleware implements Middleware
{
    public function __construct(private readonly AuditLog $audit)
    {
    }

    public function __invoke(RequestContext $ctx, Closure $next): mixed
    {
        $this->audit->record($ctx->method(), $ctx->path());

        return $next($ctx);
    }
}
