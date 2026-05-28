<?php

declare(strict_types=1);

namespace AegisSwoole\Service;

use AegisSwoole\Scope\Scope;
use AegisSwoole\Trace\TraceType;
use Closure;

class LoggingServiceMiddleware implements ServiceTransformationMiddleware
{
    public function transform(string $type, Closure $next, Scope $scope): object
    {
        $instance = $next();
        $scope->trace()->log(TraceType::ServiceResolve, "middleware:{$type}");
        return $instance;
    }
}
