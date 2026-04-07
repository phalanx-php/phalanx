<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Handler\HandlerConfig;

final class WsConfig extends HandlerConfig
{
    /**
     * @param list<string> $subprotocols
     * @param list<string> $tags
     * @param list<class-string> $middleware
     */
    public function __construct(
        public private(set) int $maxMessageSize = 65536,
        public private(set) int $maxFrameSize = 65536,
        public private(set) float $pingInterval = 30.0,
        public private(set) float $closeTimeout = 5.0,
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
}
