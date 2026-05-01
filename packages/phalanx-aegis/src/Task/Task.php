<?php

declare(strict_types=1);

namespace Phalanx\Task;

use Phalanx\Scope\ExecutionScope;
use Closure;
use ReflectionFunction;
use RuntimeException;

/**
 * Closure adapter to give a bare closure a class identity.
 *
 * Static-closure enforcement is the load-bearing rule: non-static closures
 * capture $this. In a coroutine event loop that runs for hours or days, a
 * captured $this creates a reference cycle that the cycle collector cannot
 * deterministically reap. Task::of() reflects on the closure and refuses to
 * accept non-static closures.
 *
 * Use Task::of(static fn(...) => ...) for trivial wrapped logic. For tasks with
 * behavioral declarations (Retryable, HasTimeout, Traceable, ...), write a
 * named class that implements the appropriate interfaces directly.
 */
class Task implements Executable
{
    private function __construct(private readonly Closure $fn)
    {
    }

    public static function of(Closure $fn): self
    {
        $r = new ReflectionFunction($fn);
        if (!$r->isStatic()) {
            throw new RuntimeException(
                'Task::of() requires a static closure. Non-static closures capture $this and leak in long-running coroutines.',
            );
        }
        return new self($fn);
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return ($this->fn)($scope);
    }
}
