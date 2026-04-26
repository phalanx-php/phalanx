<?php

declare(strict_types=1);

namespace Phalanx\Ui\Middleware;

use Closure;
use Phalanx\Http\Contract\Middleware;
use Phalanx\Http\RequestScope;
use Phalanx\Ui\Signal\Envelope;
use Phalanx\Ui\Signal\SignalCollector;

final class EnvelopeMiddleware implements Middleware
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
}
