<?php

declare(strict_types=1);

namespace Phalanx\Task;

/**
 * Marker interface for tasks that need only service resolution and attribute
 * access (no concurrency primitives, no cancellation, no disposal).
 *
 * Implementations expose `__invoke()` whose first parameter is `Phalanx\Scope`
 * (or a narrower subtype). Additional parameters may be declared after the
 * scope -- the framework hydrates them through input pipelines like
 * `Phalanx\Http\Contract\InputHydrator`. Because the parameter shape is
 * variable, this interface declares no method signature; the dispatcher
 * relies on the framework's resolution machinery to invoke implementations
 * correctly.
 *
 * @method mixed __invoke(\Phalanx\Scope $scope, mixed ...$args)
 *
 * @see Executable For tasks requiring full ExecutionScope capabilities
 */
interface Scopeable
{
}
