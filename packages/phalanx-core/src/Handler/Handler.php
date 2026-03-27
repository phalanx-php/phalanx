<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final readonly class Handler
{
    public function __construct(
        public Scopeable|Executable $task,
        public HandlerConfig $config,
    ) {
    }

    /**
     * Create a handler with explicit config.
     */
    public static function of(Scopeable|Executable $task, ?HandlerConfig $config = null): self
    {
        return new self($task, $config ?? new HandlerConfig());
    }
}
