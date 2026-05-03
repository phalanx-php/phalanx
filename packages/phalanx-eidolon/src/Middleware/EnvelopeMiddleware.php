<?php

declare(strict_types=1);

namespace Phalanx\Eidolon\Middleware;

use Closure;
use Phalanx\Eidolon\Signal\Envelope;
use Phalanx\Eidolon\Signal\SignalCollector;
use Phalanx\Stoa\Contract\Middleware;
use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Executable;

final class EnvelopeMiddleware implements Middleware, Executable
{
    public function handle(RequestScope $scope, Closure $next): mixed
    {
        $result = $next($scope);

        if (!is_array($result)) {
            return $result;
        }

        if (Envelope::isEnvelope($result)) {
            return $result;
        }

        $collector = $scope->service(SignalCollector::class);
        $traceId   = $scope->attribute('trace_id');

        return Envelope::wrap($result, $collector, is_string($traceId) ? $traceId : null);
    }

    public function __invoke(RequestScope $scope, Closure $next): mixed
    {
        return $this->handle($scope, $next);
    }
}
