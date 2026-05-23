<?php

declare(strict_types=1);

namespace Phalanx\Handler;

/**
 * Base configuration for handlers.
 *
 * Extended by RouteConfig and CommandConfig for protocol-specific metadata.
 * Middleware is stored as a list of class-strings; each is resolved through
 * the service container at dispatch time, the same lifecycle as handlers.
 */
class HandlerConfig
{
    /**
     * @param list<string> $tags
     * @param list<class-string> $middleware
     */
    public function __construct(
        protected(set) array $tags = [],
        protected(set) int $priority = 0,
        protected(set) array $middleware = [],
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

    /**
     * @param class-string ...$middleware
     */
    public function withMiddleware(string ...$middleware): static
    {
        $clone = clone $this;
        $clone->middleware = array_values([...$this->middleware, ...$middleware]);
        return $clone;
    }
}
