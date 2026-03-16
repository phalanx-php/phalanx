<?php

declare(strict_types=1);

namespace Convoy\WebSocket\Tests\Unit;

use Convoy\WebSocket\WsCloseCode;
use Convoy\WebSocket\WsMessage;
use Ratchet\RFC6455\Messaging\Frame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsMessageTest extends TestCase
{
    #[Test]
    public function text_factory_creates_text_message(): void
    {
        $msg = WsMessage::text('hello');

        $this->assertSame('hello', $msg->payload);
        $this->assertSame(Frame::OP_TEXT, $msg->opcode);
        $this->assertTrue($msg->isText);
        $this->assertFalse($msg->isBinary);
        $this->assertFalse($msg->isClose);
        $this->assertFalse($msg->isPing);
        $this->assertFalse($msg->isPong);
    }

    #[Test]
    public function binary_factory_creates_binary_message(): void
    {
        $data = random_bytes(16);
        $msg = WsMessage::binary($data);

        $this->assertSame($data, $msg->payload);
        $this->assertTrue($msg->isBinary);
        $this->assertFalse($msg->isText);
    }

    #[Test]
    public function close_factory_encodes_code_and_reason(): void
    {
        $msg = WsMessage::close(WsCloseCode::Normal, 'goodbye');

        $this->assertTrue($msg->isClose);
        $this->assertSame(WsCloseCode::Normal, $msg->closeCode);

        $decoded = unpack('n', substr($msg->payload, 0, 2))[1];
        $this->assertSame(1000, $decoded);
        $this->assertSame('goodbye', substr($msg->payload, 2));
    }

    #[Test]
    public function close_factory_defaults_to_normal(): void
    {
        $msg = WsMessage::close();

        $this->assertSame(WsCloseCode::Normal, $msg->closeCode);
    }

    #[Test]
    public function ping_factory_creates_ping(): void
    {
        $msg = WsMessage::ping('heartbeat');

        $this->assertTrue($msg->isPing);
        $this->assertSame('heartbeat', $msg->payload);
    }

    #[Test]
    public function pong_factory_creates_pong(): void
    {
        $msg = WsMessage::pong('heartbeat');

        $this->assertTrue($msg->isPong);
        $this->assertSame('heartbeat', $msg->payload);
    }

    #[Test]
    public function json_decodes_text_payload(): void
    {
        $msg = WsMessage::text('{"type":"chat","body":"hi"}');

        $data = $msg->json();

        $this->assertSame('chat', $data['type']);
        $this->assertSame('hi', $data['body']);
    }

    #[Test]
    public function json_throws_on_invalid_payload(): void
    {
        $msg = WsMessage::text('not json');

        $this->expectException(\JsonException::class);
        $msg->json();
    }

    #[Test]
    public function to_frame_produces_valid_frame(): void
    {
        $msg = WsMessage::text('test');
        $frame = $msg->toFrame();

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame(Frame::OP_TEXT, $frame->getOpcode());
        $this->assertSame('test', $frame->getPayload());
    }

    #[Test]
    public function from_frame_round_trips_text(): void
    {
        $original = WsMessage::text('round trip');
        $frame = $original->toFrame();
        $restored = WsMessage::fromFrame($frame);

        $this->assertSame($original->payload, $restored->payload);
        $this->assertSame($original->opcode, $restored->opcode);
        $this->assertTrue($restored->isText);
    }

    #[Test]
    public function from_frame_decodes_close_code(): void
    {
        $closeFrame = new Frame(
            pack('n', 1001) . 'going away',
            true,
            Frame::OP_CLOSE,
        );

        $msg = WsMessage::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertSame(WsCloseCode::GoingAway, $msg->closeCode);
        $this->assertSame('going away', $msg->payload);
    }

    #[Test]
    public function from_frame_handles_close_without_payload(): void
    {
        $closeFrame = new Frame('', true, Frame::OP_CLOSE);
        $msg = WsMessage::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertNull($msg->closeCode);
        $this->assertSame('', $msg->payload);
    }
}
