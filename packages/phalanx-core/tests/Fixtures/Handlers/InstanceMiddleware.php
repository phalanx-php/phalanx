<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Handlers;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Test middleware that wraps the inner handler's string result with
 * "instance(" / ")" markers. Used to assert HasMiddleware execution.
 */
final class InstanceMiddleware implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        $next = $scope->attribute('handler.next');
        assert($next instanceof Scopeable || $next instanceof Executable);

        return 'instance(' . $scope->execute($next) . ')';
    }
}
