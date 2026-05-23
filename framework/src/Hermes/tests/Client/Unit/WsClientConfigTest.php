<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Client\Tests\Unit;

use Phalanx\Hermes\Client\WsClientConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsClientConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = WsClientConfig::default();

        $this->assertSame(5.0, $config->connectTimeout);
        $this->assertSame(1.0, $config->recvTimeout);
        $this->assertSame(65536, $config->maxMessageSize);
        $this->assertSame(65536, $config->maxFrameSize);
        $this->assertSame(30.0, $config->pingInterval);
        $this->assertSame(128, $config->inboundBufferSize);
        $this->assertSame(64, $config->writeQueueSize);
    }

    #[Test]
    public function withConnectTimeoutReturnsNewInstance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withConnectTimeout(10.0);

        $this->assertNotSame($original, $modified);
        $this->assertSame(5.0, $original->connectTimeout);
        $this->assertSame(10.0, $modified->connectTimeout);
    }

    #[Test]
    public function withRecvTimeoutReturnsNewInstance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withRecvTimeout(2.5);

        $this->assertNotSame($original, $modified);
        $this->assertSame(1.0, $original->recvTimeout);
        $this->assertSame(2.5, $modified->recvTimeout);
    }

    #[Test]
    public function withMaxMessageSizeReturnsNewInstance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withMaxMessageSize(1024 * 1024);

        $this->assertNotSame($original, $modified);
        $this->assertSame(65536, $original->maxMessageSize);
        $this->assertSame(1024 * 1024, $modified->maxMessageSize);
    }

    #[Test]
    public function withMaxFrameSizeReturnsNewInstance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withMaxFrameSize(32768);

        $this->assertNotSame($original, $modified);
        $this->assertSame(65536, $original->maxFrameSize);
        $this->assertSame(32768, $modified->maxFrameSize);
    }

    #[Test]
    public function withPingIntervalReturnsNewInstance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withPingInterval(60.0);

        $this->assertNotSame($original, $modified);
        $this->assertSame(30.0, $original->pingInterval);
        $this->assertSame(60.0, $modified->pingInterval);
    }

    #[Test]
    public function withInboundBufferSizeReturnsNewInstance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withInboundBufferSize(256);

        $this->assertNotSame($original, $modified);
        $this->assertSame(128, $original->inboundBufferSize);
        $this->assertSame(256, $modified->inboundBufferSize);
    }

    #[Test]
    public function withWriteQueueSizeReturnsNewInstance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withWriteQueueSize(256);

        $this->assertNotSame($original, $modified);
        $this->assertSame(64, $original->writeQueueSize);
        $this->assertSame(256, $modified->writeQueueSize);
    }

    #[Test]
    public function buildersAreChainable(): void
    {
        $config = WsClientConfig::default()
            ->withConnectTimeout(10.0)
            ->withRecvTimeout(0.5)
            ->withPingInterval(15.0)
            ->withMaxMessageSize(1024 * 1024)
            ->withInboundBufferSize(64)
            ->withWriteQueueSize(128);

        $this->assertSame(10.0, $config->connectTimeout);
        $this->assertSame(0.5, $config->recvTimeout);
        $this->assertSame(15.0, $config->pingInterval);
        $this->assertSame(1024 * 1024, $config->maxMessageSize);
        $this->assertSame(64, $config->inboundBufferSize);
        $this->assertSame(128, $config->writeQueueSize);
    }
}
