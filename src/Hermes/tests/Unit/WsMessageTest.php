<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Unit;

use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server as WebSocketServer;
use Phalanx\Hermes\WsCloseCode;
use Phalanx\Hermes\WsMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsMessageTest extends TestCase
{
    #[Test]
    public function textFactoryCreatesTextMessage(): void
    {
        $msg = WsMessage::text('hello');

        $this->assertSame('hello', $msg->payload);
        $this->assertSame(WebSocketServer::WEBSOCKET_OPCODE_TEXT, $msg->opcode);
        $this->assertTrue($msg->isText);
        $this->assertFalse($msg->isBinary);
        $this->assertFalse($msg->isClose);
        $this->assertFalse($msg->isPing);
        $this->assertFalse($msg->isPong);
    }

    #[Test]
    public function binaryFactoryCreatesBinaryMessage(): void
    {
        $data = random_bytes(16);
        $msg = WsMessage::binary($data);

        $this->assertSame($data, $msg->payload);
        $this->assertTrue($msg->isBinary);
        $this->assertFalse($msg->isText);
    }

    #[Test]
    public function closeFactoryEncodesCodeAndReason(): void
    {
        $msg = WsMessage::close(WsCloseCode::Normal, 'goodbye');

        $this->assertTrue($msg->isClose);
        $this->assertSame(WsCloseCode::Normal, $msg->closeCode);

        $decoded = unpack('n', substr($msg->payload, 0, 2));
        $this->assertIsArray($decoded);
        $this->assertSame(1000, $decoded[1]);
        $this->assertSame('goodbye', substr($msg->payload, 2));
    }

    #[Test]
    public function closeFactoryDefaultsToNormal(): void
    {
        $msg = WsMessage::close();

        $this->assertSame(WsCloseCode::Normal, $msg->closeCode);
    }

    #[Test]
    public function pingFactoryCreatesPing(): void
    {
        $msg = WsMessage::ping('heartbeat');

        $this->assertTrue($msg->isPing);
        $this->assertSame('heartbeat', $msg->payload);
    }

    #[Test]
    public function pongFactoryCreatesPong(): void
    {
        $msg = WsMessage::pong('heartbeat');

        $this->assertTrue($msg->isPong);
        $this->assertSame('heartbeat', $msg->payload);
    }

    #[Test]
    public function decodeDecodesTextPayload(): void
    {
        $msg = WsMessage::text('{"type":"chat","body":"hi"}');

        $data = $msg->decode();

        $this->assertSame('chat', $data['type']);
        $this->assertSame('hi', $data['body']);
    }

    #[Test]
    public function decodeThrowsOnInvalidPayload(): void
    {
        $msg = WsMessage::text('not json');

        $this->expectException(\JsonException::class);
        $msg->decode();
    }

    #[Test]
    public function toFrameProducesValidFrame(): void
    {
        $msg = WsMessage::text('test');
        $frame = $msg->toFrame();

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame(WebSocketServer::WEBSOCKET_OPCODE_TEXT, $frame->opcode);
        $this->assertSame('test', $frame->data);
        $this->assertTrue($frame->finish);
    }

    #[Test]
    public function fromFrameRoundTripsText(): void
    {
        $original = WsMessage::text('round trip');
        $frame = $original->toFrame();
        $restored = WsMessage::fromFrame($frame);

        $this->assertSame($original->payload, $restored->payload);
        $this->assertSame($original->opcode, $restored->opcode);
        $this->assertTrue($restored->isText);
    }

    #[Test]
    public function fromFrameCreatesDistinctOwnedMessages(): void
    {
        $first = WsMessage::fromFrame(WsMessage::text('first')->toFrame());
        $second = WsMessage::fromFrame(WsMessage::text('second')->toFrame());

        $this->assertNotSame(spl_object_id($first), spl_object_id($second));
        $this->assertSame('first', $first->payload);
        $this->assertSame('second', $second->payload);
    }

    #[Test]
    public function fromFrameDecodesCloseCode(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = pack('n', 1001) . 'going away';
        $closeFrame->opcode = WebSocketServer::WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = WebSocketServer::WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = WsMessage::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertSame(WsCloseCode::GoingAway, $msg->closeCode);
        $this->assertSame('going away', $msg->payload);
    }

    #[Test]
    public function fromFrameHandlesCloseWithoutPayload(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = '';
        $closeFrame->opcode = WebSocketServer::WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = WebSocketServer::WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = WsMessage::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertNull($msg->closeCode);
        $this->assertSame('', $msg->payload);
    }

    #[Test]
    public function fromFrameHandlesShortClosePayload(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = "\x03";
        $closeFrame->opcode = WebSocketServer::WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = WebSocketServer::WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = WsMessage::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertNull($msg->closeCode);
        $this->assertSame("\x03", $msg->payload);
    }

    #[Test]
    public function fromFrameHandlesUnknownCloseCode(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = pack('n', 9999) . 'olympus';
        $closeFrame->opcode = WebSocketServer::WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = WebSocketServer::WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = WsMessage::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertNull($msg->closeCode);
        $this->assertSame('olympus', $msg->payload);
    }
}
