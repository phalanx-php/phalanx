<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Handlers;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Test middleware that wraps the inner handler's string result with
 * "instance(" / ")" markers. Used to assert HasMiddleware execution.
 */
final class InstanceMiddleware implements Executable
{
    public function __invoke(ExecutionScope $scope, Closure $next): mixed
    {
        return 'instance(' . $next($scope) . ')';
    }
}
