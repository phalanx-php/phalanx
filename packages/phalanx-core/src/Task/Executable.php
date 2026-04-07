<?php

declare(strict_types=1);

namespace Phalanx\Task;

/**
 * Marker interface for tasks that need full ExecutionScope capabilities --
 * concurrency primitives, cancellation, disposal, etc.
 *
 * Implementations expose `__invoke()` whose first parameter is
 * `Phalanx\ExecutionScope` (or a narrower subtype). Additional parameters
 * may be declared after the scope -- the framework hydrates them through
 * input pipelines like `Phalanx\Http\Contract\InputHydrator`. Because the
 * parameter shape is variable, this interface declares no method signature;
 * the dispatcher relies on the framework's resolution machinery to invoke
 * implementations correctly.
 *
 * @method mixed __invoke(\Phalanx\ExecutionScope $scope, mixed ...$args)
 *
 * @see Scopeable For tasks needing only service resolution
 */
interface Executable
{
}
