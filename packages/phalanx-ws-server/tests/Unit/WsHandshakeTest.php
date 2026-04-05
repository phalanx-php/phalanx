<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\WebSocket\WsHandshake;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsHandshakeTest extends TestCase
{
    #[Test]
    public function successful_handshake_returns_101(): void
    {
        $handshake = new WsHandshake();

        $request = new Request('GET', '/chat', [
            'Host' => 'localhost',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            'Sec-WebSocket-Version' => '13',
        ]);

        $response = $handshake->negotiate($request);

        $this->assertSame(101, $response->getStatusCode());
        $this->assertTrue($handshake->isSuccessful($response));
        $this->assertSame('websocket', strtolower($response->getHeaderLine('Upgrade')));
        $this->assertSame('Upgrade', $response->getHeaderLine('Connection'));
        $this->assertNotEmpty($response->getHeaderLine('Sec-WebSocket-Accept'));
    }

    #[Test]
    public function missing_key_returns_400(): void
    {
        $handshake = new WsHandshake();

        $request = new Request('GET', '/chat', [
            'Host' => 'localhost',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
        ]);

        $response = $handshake->negotiate($request);

        $this->assertFalse($handshake->isSuccessful($response));
        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function wrong_method_returns_405(): void
    {
        $handshake = new WsHandshake();

        $request = new Request('POST', '/chat', [
            'Host' => 'localhost',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            'Sec-WebSocket-Version' => '13',
        ]);

        $response = $handshake->negotiate($request);

        $this->assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function wrong_version_returns_426(): void
    {
        $handshake = new WsHandshake();

        $request = new Request('GET', '/chat', [
            'Host' => 'localhost',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            'Sec-WebSocket-Version' => '8',
        ]);

        $response = $handshake->negotiate($request);

        $this->assertSame(426, $response->getStatusCode());
    }

    #[Test]
    public function subprotocol_negotiation(): void
    {
        $handshake = new WsHandshake(subprotocols: ['graphql-ws', 'json']);

        $request = new Request('GET', '/ws', [
            'Host' => 'localhost',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Protocol' => 'graphql-ws',
        ]);

        $response = $handshake->negotiate($request);

        $this->assertSame(101, $response->getStatusCode());
        $this->assertSame('graphql-ws', $response->getHeaderLine('Sec-WebSocket-Protocol'));
    }
}
