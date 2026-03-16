<?php

declare(strict_types=1);

namespace Convoy\Task;

use Convoy\ExecutionScope;

/**
 * Task requiring full execution capabilities.
 *
 * Implement this interface for tasks that need concurrency primitives,
 * cancellation checking, or other ExecutionScope capabilities.
 *
 * @see Scopeable For tasks requiring only service resolution
 */
interface Executable
{
    public function __invoke(ExecutionScope $scope): mixed;
}
