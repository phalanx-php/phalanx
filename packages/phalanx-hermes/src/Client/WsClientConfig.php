<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Client;

final class WsClientConfig
{
    public function __construct(
        private(set) float $connectTimeout = 5.0,
        private(set) float $recvTimeout = 1.0,
        private(set) int $maxMessageSize = 65536,
        private(set) int $maxFrameSize = 65536,
        private(set) float $pingInterval = 30.0,
        private(set) int $inboundBufferSize = 128,
        private(set) int $writeQueueSize = 64,
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

    public function withRecvTimeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->recvTimeout = $seconds;

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

    public function withInboundBufferSize(int $size): self
    {
        $clone = clone $this;
        $clone->inboundBufferSize = $size;

        return $clone;
    }

    public function withWriteQueueSize(int $size): self
    {
        $clone = clone $this;
        $clone->writeQueueSize = $size;

        return $clone;
    }
}
