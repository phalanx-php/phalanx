<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\System\TcpClient;
use Phalanx\System\TlsOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Construction-time semantics for the TLS path. Live TLS handshake coverage
 * lives in integration tests where a localhost TLS endpoint is available.
 *
 * The setTlsOptions guard exists because applying SSL options to a plain
 * SWOOLE_SOCK_TCP socket is silently ignored by OpenSwoole — better to
 * surface the misuse with an explicit error than have a "TLS off but I
 * passed options" mode that looks correct at construction.
 */
final class TcpClientTlsConstructionTest extends TestCase
{
    public function testDefaultsToPlainTcp(): void
    {
        $client = new TcpClient();

        self::assertFalse($client->tls);
    }

    public function testTlsConstructionFlagsTlsOn(): void
    {
        $client = new TcpClient(tls: true);

        self::assertTrue($client->tls);
    }

    public function testTlsConstructionWithOptionsDoesNotThrow(): void
    {
        $client = new TcpClient(
            tls: true,
            tlsOptions: new TlsOptions(verifyPeer: true, hostName: 'api.example.com'),
        );

        self::assertTrue($client->tls);
    }

    public function testSetTlsOptionsRejectsNonTlsClient(): void
    {
        $client = new TcpClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TLS not enabled');

        $client->setTlsOptions(new TlsOptions());
    }
}
