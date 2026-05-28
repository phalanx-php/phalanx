<?php

declare(strict_types=1);

namespace AegisSwoole\Middleware;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\Scopeable;
use AegisSwoole\Task\Traceable;
use AegisSwoole\Trace\TraceType;
use Closure;
use Throwable;

class TraceMiddleware implements TaskMiddleware
{
    public function handle(Scopeable|Executable|Closure $task, ExecutionScope $scope, Closure $next): mixed
    {
        if (!$task instanceof Traceable) {
            return $next($scope);
        }
        $name = $task->traceName();
        $scope->trace()->log(TraceType::Execute, $name, ['phase' => 'start']);
        try {
            $result = $next($scope);
            $scope->trace()->log(TraceType::Execute, $name, ['phase' => 'end']);
            return $result;
        } catch (Throwable $e) {
            $scope->trace()->log(TraceType::Execute, $name, ['phase' => 'error', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
