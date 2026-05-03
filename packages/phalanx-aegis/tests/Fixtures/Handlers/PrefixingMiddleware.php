<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Handlers;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Test middleware that prefixes "before:" and suffixes ":after" around the
 * inner handler's string result. Used to verify middleware composition
 * order and execution wrapping without relying on captured closure state.
 */
final class PrefixingMiddleware implements Executable
{
    public function __invoke(ExecutionScope $scope, Closure $next): mixed
    {
        $inner = $next($scope);

        return 'before:' . $inner . ':after';
    }
}
