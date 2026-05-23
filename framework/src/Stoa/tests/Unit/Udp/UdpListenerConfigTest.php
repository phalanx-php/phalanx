<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Udp;

use Phalanx\Stoa\Udp\UdpListenerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UdpListenerConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreSafeForBindingOnAnyInterface(): void
    {
        $config = new UdpListenerConfig();

        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(0, $config->port);
        self::assertFalse($config->broadcast);
        self::assertSame(65507, $config->maxPacketSize);
        self::assertSame(1, $config->workerNum);
    }

    #[Test]
    public function valuesArePropagatedExactly(): void
    {
        $config = new UdpListenerConfig(
            host: '127.0.0.1',
            port: 9999,
            broadcast: true,
            maxPacketSize: 1024,
            workerNum: 4,
        );

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9999, $config->port);
        self::assertTrue($config->broadcast);
        self::assertSame(1024, $config->maxPacketSize);
        self::assertSame(4, $config->workerNum);
    }
}
