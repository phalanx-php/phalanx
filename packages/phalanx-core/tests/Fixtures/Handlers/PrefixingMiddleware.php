<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Handlers;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Test middleware that prefixes "before:" and suffixes ":after" around the
 * inner handler's string result. Used to verify middleware composition
 * order and execution wrapping without relying on captured closure state.
 */
final class PrefixingMiddleware implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        $next = $scope->attribute('handler.next');
        assert($next instanceof Scopeable || $next instanceof Executable);

        $inner = $scope->execute($next);

        return 'before:' . $inner . ':after';
    }
}
