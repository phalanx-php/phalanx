<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Dispatch unit for the handler system.
 *
 * `$task` is a class-string of a Scopeable or Executable. Handlers are
 * resolved at dispatch time via `HandlerResolver`, which constructs the
 * instance with constructor-injected services. Storing the class-string
 * (rather than an instance) keeps route/command tables cheap to build,
 * makes dependencies visible at the class signature, and lets the framework
 * own the lifecycle.
 */
final readonly class Handler
{
    /**
     * @param class-string<Scopeable|Executable> $task
     */
    public function __construct(
        public string $task,
        public HandlerConfig $config,
    ) {
    }

    /**
     * @param class-string<Scopeable|Executable> $task
     */
    public static function of(string $task, ?HandlerConfig $config = null): self
    {
        return new self($task, $config ?? new HandlerConfig());
    }
}
