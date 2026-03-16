<?php

declare(strict_types=1);

namespace Convoy\Task;

use Convoy\Scope;

/**
 * Task requiring only service resolution and attribute access.
 *
 * Implement this interface for tasks that don't need concurrency primitives,
 * cancellation checking, or other ExecutionScope capabilities.
 *
 * @see Executable For tasks requiring full execution capabilities
 */
interface Scopeable
{
    public function __invoke(Scope $scope): mixed;
}
