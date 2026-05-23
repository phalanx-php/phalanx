<?php

declare(strict_types=1);

namespace Phalanx\Scope\Stream;

use Generator;
use Phalanx\Scope\ExecutionScope;

/**
 * A pull-based stream of values produced under a managed ExecutionScope.
 *
 * Implementations yield values via Generator and honor scope cancellation
 * through $scope->throwIfCancelled(). Producer-side work must be spawned
 * through the same scope so cancellation and cleanup stay supervised.
 *
 * @template TValue
 */
interface StreamSource
{
    /**
     * @return Generator<int, TValue>
     */
    public function __invoke(ExecutionScope $scope): Generator;
}
