<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Client;

final class WsClientConfig
{
    public function __construct(
        public private(set) float $connectTimeout = 5.0,
        public private(set) int $maxMessageSize = 65536,
        public private(set) int $maxFrameSize = 65536,
        public private(set) float $pingInterval = 30.0,
        public private(set) ?int $maxReconnectAttempts = null,
        public private(set) float $reconnectBaseDelay = 1.0,
        public private(set) int $inboundBufferSize = 128,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public function withConnectTimeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->connectTimeout = $seconds;

        return $clone;
    }

    public function withMaxMessageSize(int $bytes): self
    {
        $clone = clone $this;
        $clone->maxMessageSize = $bytes;

        return $clone;
    }

    public function withMaxFrameSize(int $bytes): self
    {
        $clone = clone $this;
        $clone->maxFrameSize = $bytes;

        return $clone;
    }

    public function withPingInterval(float $seconds): self
    {
        $clone = clone $this;
        $clone->pingInterval = $seconds;

        return $clone;
    }

    public function withReconnect(int $maxAttempts, float $baseDelay = 1.0): self
    {
        $clone = clone $this;
        $clone->maxReconnectAttempts = $maxAttempts;
        $clone->reconnectBaseDelay = $baseDelay;

        return $clone;
    }

    public function withInboundBufferSize(int $size): self
    {
        $clone = clone $this;
        $clone->inboundBufferSize = $size;

        return $clone;
    }
}
