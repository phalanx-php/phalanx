<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Handler\HandlerConfig;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class WsConfig extends HandlerConfig
{
    public function __construct(
        public private(set) int $maxMessageSize = 65536,
        public private(set) int $maxFrameSize = 65536,
        public private(set) float $pingInterval = 30.0,
        public private(set) float $closeTimeout = 5.0,
        /** @var list<string> */
        public private(set) array $subprotocols = [],
        array $tags = [],
        int $priority = 0,
        array $middleware = [],
    ) {
        parent::__construct($tags, $priority, $middleware);
    }

    public function withMaxMessageSize(int $size): self
    {
        $clone = clone $this;
        $clone->maxMessageSize = $size;
        return $clone;
    }

    public function withMaxFrameSize(int $size): self
    {
        $clone = clone $this;
        $clone->maxFrameSize = $size;
        return $clone;
    }

    public function withPingInterval(float $seconds): self
    {
        $clone = clone $this;
        $clone->pingInterval = $seconds;
        return $clone;
    }

    public function withCloseTimeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->closeTimeout = $seconds;
        return $clone;
    }

    public function withSubprotocols(string ...$protocols): self
    {
        $clone = clone $this;
        $clone->subprotocols = array_values($protocols);
        return $clone;
    }

    #[\Override]
    public function withMiddleware(Scopeable|Executable ...$middleware): static
    {
        $clone = clone $this;
        $clone->middleware = array_values([...$this->middleware, ...$middleware]);
        return $clone;
    }

    #[\Override]
    public function withTags(string ...$tags): static
    {
        $clone = clone $this;
        $clone->tags = array_values([...$this->tags, ...$tags]);
        return $clone;
    }

    #[\Override]
    public function withPriority(int $priority): static
    {
        $clone = clone $this;
        $clone->priority = $priority;
        return $clone;
    }
}
