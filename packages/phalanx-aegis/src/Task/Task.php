<?php

declare(strict_types=1);

namespace Phalanx\Task;

use Closure;
use Phalanx\Scope\ExecutionScope;
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
 * Use Task::of(static fn(...) => ...) for trivial wrapped logic. For tasks
 * with behavioral declarations (Retryable, HasTimeout, Traceable, ...), write
 * a named class that implements the appropriate interfaces directly.
 *
 * Identity for diagnostics:
 *   Task::of(...)         -> sourceLocation is "filename.php:line" via reflection
 *   Task::named($n, ...)  -> sourceLocation is the explicit $n
 *   reflection-fallback   -> self::class when the closure has no resolvable file
 */
class Task implements Executable
{
    public string $sourceLocation = '';

    private function __construct(private readonly Closure $fn)
    {
    }

    public static function of(Closure $fn): self
    {
        $reflection = new ReflectionFunction($fn);
        if (!$reflection->isStatic()) {
            throw new RuntimeException(
                'Task::of() requires a static closure. Non-static closures capture $this '
                . 'and leak in long-running coroutines.',
            );
        }

        $task = new self($fn);
        $task->sourceLocation = self::deriveLocation($reflection);
        return $task;
    }

    public static function named(string $name, Closure $fn): self
    {
        $task = self::of($fn);
        $task->sourceLocation = $name;
        return $task;
    }

    private static function deriveLocation(ReflectionFunction $reflection): string
    {
        $file = $reflection->getFileName();
        if ($file === false) {
            return self::class;
        }
        return basename($file) . ':' . $reflection->getStartLine();
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return ($this->fn)($scope);
    }
}
