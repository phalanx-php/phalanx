<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\WebSocket\WsMessage;
use Ratchet\RFC6455\Messaging\Frame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsMessageJsonTest extends TestCase
{
    #[Test]
    public function json_factory_creates_text_message_with_encoded_payload(): void
    {
        $msg = WsMessage::json(['type' => 'chat', 'body' => 'hi']);

        $this->assertTrue($msg->isText);
        $this->assertSame(Frame::OP_TEXT, $msg->opcode);
        $this->assertSame('{"type":"chat","body":"hi"}', $msg->payload);
    }

    #[Test]
    public function json_factory_encodes_nested_data(): void
    {
        $data = ['users' => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']]];
        $msg = WsMessage::json($data);

        $this->assertSame(json_encode($data), $msg->payload);
    }

    #[Test]
    public function json_factory_accepts_flags(): void
    {
        $msg = WsMessage::json(['path' => '/foo/bar'], JSON_UNESCAPED_SLASHES);

        $this->assertSame('{"path":"/foo/bar"}', $msg->payload);
    }

    #[Test]
    public function json_factory_throws_on_unencodable_data(): void
    {
        $this->expectException(\JsonException::class);

        WsMessage::json(fopen('php://memory', 'r'));
    }

    #[Test]
    public function json_factory_round_trips_with_decode(): void
    {
        $data = ['type' => 'event', 'count' => 42, 'nested' => ['a' => true]];
        $msg = WsMessage::json($data);
        $decoded = $msg->decode();

        $this->assertSame($data, $decoded);
    }

    #[Test]
    public function json_factory_encodes_scalar_values(): void
    {
        $msg = WsMessage::json('hello');
        $this->assertSame('"hello"', $msg->payload);

        $msg = WsMessage::json(42);
        $this->assertSame('42', $msg->payload);

        $msg = WsMessage::json(true);
        $this->assertSame('true', $msg->payload);

        $msg = WsMessage::json(null);
        $this->assertSame('null', $msg->payload);
    }
}
