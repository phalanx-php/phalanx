<?php

declare(strict_types=1);

namespace Phalanx\Eidolon\Middleware;

use Closure;
use Phalanx\Eidolon\Signal\Envelope;
use Phalanx\Eidolon\Signal\SignalCollector;
use Phalanx\Stoa\Contract\Middleware;
use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Executable;

final class EnvelopeMiddleware implements Middleware, Executable
{
    public function __invoke(RequestContext $ctx, Closure $next): mixed
    {
        return $this->handle($ctx, $next);
    }

    public function handle(RequestContext $ctx, Closure $next): mixed
    {
        $result = $next($ctx);

        if (!is_array($result)) {
            return $result;
        }

        if (Envelope::isEnvelope($result)) {
            return $result;
        }

        $collector = $ctx->service(SignalCollector::class);
        $traceId = $ctx->service(EnvelopeTraceId::class)->value;

        return Envelope::wrap($result, $collector, $traceId);
    }
}
