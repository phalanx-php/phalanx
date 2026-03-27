<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Base configuration for handlers.
 *
 * Extended by RouteConfig and CommandConfig for protocol-specific metadata.
 */
class HandlerConfig
{
    /**
     * @param list<string> $tags
     * @param list<Scopeable|Executable> $middleware
     */
    public function __construct(
        public protected(set) array $tags = [],
        public protected(set) int $priority = 0,
        public protected(set) array $middleware = [],
    ) {
    }

    public function withTags(string ...$tags): static
    {
        $clone = clone $this;
        $clone->tags = array_values([...$this->tags, ...$tags]);
        return $clone;
    }

    public function withPriority(int $priority): static
    {
        $clone = clone $this;
        $clone->priority = $priority;
        return $clone;
    }

    public function withMiddleware(Scopeable|Executable ...$middleware): static
    {
        $clone = clone $this;
        $clone->middleware = array_values([...$this->middleware, ...$middleware]);
        return $clone;
    }
}
