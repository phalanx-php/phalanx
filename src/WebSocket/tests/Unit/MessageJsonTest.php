<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\WebSocket\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsMessageJsonTest extends TestCase
{
    #[Test]
    public function jsonFactoryCreatesTextMessageWithEncodedPayload(): void
    {
        $msg = \Phalanx\WebSocket\Message::json(['type' => 'chat', 'body' => 'hi']);

        $this->assertTrue($msg->isText);
        $this->assertSame(SWOOLE_WEBSOCKET_OPCODE_TEXT, $msg->opcode);
        $this->assertSame('{"type":"chat","body":"hi"}', $msg->payload);
    }

    #[Test]
    public function jsonFactoryAcceptsFlags(): void
    {
        $msg = \Phalanx\WebSocket\Message::json(['path' => '/foo/bar'], JSON_UNESCAPED_SLASHES);

        $this->assertSame('{"path":"/foo/bar"}', $msg->payload);
    }

    #[Test]
    public function jsonFactoryThrowsOnUnencodableData(): void
    {
        $this->expectException(\JsonException::class);

        \Phalanx\WebSocket\Message::json(fopen('php://memory', 'r'));
    }
}
