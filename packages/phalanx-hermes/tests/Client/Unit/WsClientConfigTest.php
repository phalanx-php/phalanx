<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Client\Tests\Unit;

use Phalanx\Hermes\Client\WsClientConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsClientConfigTest extends TestCase
{
    #[Test]
    public function default_values(): void
    {
        $config = WsClientConfig::default();

        $this->assertSame(5.0, $config->connectTimeout);
        $this->assertSame(65536, $config->maxMessageSize);
        $this->assertSame(65536, $config->maxFrameSize);
        $this->assertSame(30.0, $config->pingInterval);
        $this->assertNull($config->maxReconnectAttempts);
        $this->assertSame(1.0, $config->reconnectBaseDelay);
        $this->assertSame(128, $config->inboundBufferSize);
    }

    #[Test]
    public function with_connect_timeout_returns_new_instance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withConnectTimeout(10.0);

        $this->assertNotSame($original, $modified);
        $this->assertSame(5.0, $original->connectTimeout);
        $this->assertSame(10.0, $modified->connectTimeout);
    }

    #[Test]
    public function with_max_message_size_returns_new_instance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withMaxMessageSize(1024 * 1024);

        $this->assertNotSame($original, $modified);
        $this->assertSame(65536, $original->maxMessageSize);
        $this->assertSame(1024 * 1024, $modified->maxMessageSize);
    }

    #[Test]
    public function with_max_frame_size_returns_new_instance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withMaxFrameSize(32768);

        $this->assertNotSame($original, $modified);
        $this->assertSame(65536, $original->maxFrameSize);
        $this->assertSame(32768, $modified->maxFrameSize);
    }

    #[Test]
    public function with_ping_interval_returns_new_instance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withPingInterval(60.0);

        $this->assertNotSame($original, $modified);
        $this->assertSame(30.0, $original->pingInterval);
        $this->assertSame(60.0, $modified->pingInterval);
    }

    #[Test]
    public function with_reconnect_returns_new_instance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withReconnect(5, 2.0);

        $this->assertNotSame($original, $modified);
        $this->assertNull($original->maxReconnectAttempts);
        $this->assertSame(1.0, $original->reconnectBaseDelay);
        $this->assertSame(5, $modified->maxReconnectAttempts);
        $this->assertSame(2.0, $modified->reconnectBaseDelay);
    }

    #[Test]
    public function with_reconnect_default_base_delay(): void
    {
        $config = WsClientConfig::default()->withReconnect(3);

        $this->assertSame(3, $config->maxReconnectAttempts);
        $this->assertSame(1.0, $config->reconnectBaseDelay);
    }

    #[Test]
    public function with_inbound_buffer_size_returns_new_instance(): void
    {
        $original = WsClientConfig::default();
        $modified = $original->withInboundBufferSize(256);

        $this->assertNotSame($original, $modified);
        $this->assertSame(128, $original->inboundBufferSize);
        $this->assertSame(256, $modified->inboundBufferSize);
    }

    #[Test]
    public function builders_are_chainable(): void
    {
        $config = WsClientConfig::default()
            ->withConnectTimeout(10.0)
            ->withPingInterval(15.0)
            ->withMaxMessageSize(1024 * 1024)
            ->withReconnect(3, 0.5)
            ->withInboundBufferSize(64);

        $this->assertSame(10.0, $config->connectTimeout);
        $this->assertSame(15.0, $config->pingInterval);
        $this->assertSame(1024 * 1024, $config->maxMessageSize);
        $this->assertSame(3, $config->maxReconnectAttempts);
        $this->assertSame(0.5, $config->reconnectBaseDelay);
        $this->assertSame(64, $config->inboundBufferSize);
    }
}
