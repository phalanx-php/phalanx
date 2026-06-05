<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Api\Http;

use Acme\HttpDemo\Api\Services\AuditLog;
use Closure;
use Phalanx\Http\Contract\Middleware;
use Phalanx\Http\RequestContext;

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
