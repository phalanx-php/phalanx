<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\WebSocket\CloseCode;
use Phalanx\WebSocket\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Frame;

final class WsMessageTest extends TestCase
{
    #[Test]
    public function textFactoryCreatesTextMessage(): void
    {
        $msg = \Phalanx\WebSocket\Message::text('hello');

        $this->assertSame('hello', $msg->payload);
        $this->assertSame(SWOOLE_WEBSOCKET_OPCODE_TEXT, $msg->opcode);
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
        $msg = \Phalanx\WebSocket\Message::binary($data);

        $this->assertSame($data, $msg->payload);
        $this->assertTrue($msg->isBinary);
        $this->assertFalse($msg->isText);
    }

    #[Test]
    public function closeFactoryEncodesCodeAndReason(): void
    {
        $msg = \Phalanx\WebSocket\Message::close(\Phalanx\WebSocket\CloseCode::Normal, 'goodbye');

        $this->assertTrue($msg->isClose);
        $this->assertSame(\Phalanx\WebSocket\CloseCode::Normal, $msg->closeCode);

        $decoded = unpack('n', substr($msg->payload, 0, 2));
        $this->assertIsArray($decoded);
        $this->assertSame(1000, $decoded[1]);
        $this->assertSame('goodbye', substr($msg->payload, 2));
    }

    #[Test]
    public function closeFactoryDefaultsToNormal(): void
    {
        $msg = \Phalanx\WebSocket\Message::close();

        $this->assertSame(\Phalanx\WebSocket\CloseCode::Normal, $msg->closeCode);
    }

    #[Test]
    public function pingFactoryCreatesPing(): void
    {
        $msg = \Phalanx\WebSocket\Message::ping('heartbeat');

        $this->assertTrue($msg->isPing);
        $this->assertSame('heartbeat', $msg->payload);
    }

    #[Test]
    public function pongFactoryCreatesPong(): void
    {
        $msg = \Phalanx\WebSocket\Message::pong('heartbeat');

        $this->assertTrue($msg->isPong);
        $this->assertSame('heartbeat', $msg->payload);
    }

    #[Test]
    public function decodeDecodesTextPayload(): void
    {
        $msg = \Phalanx\WebSocket\Message::text('{"type":"chat","body":"hi"}');

        $data = $msg->decode();

        $this->assertSame('chat', $data['type']);
        $this->assertSame('hi', $data['body']);
    }

    #[Test]
    public function decodeThrowsOnInvalidPayload(): void
    {
        $msg = \Phalanx\WebSocket\Message::text('not json');

        $this->expectException(\JsonException::class);
        $msg->decode();
    }

    #[Test]
    public function toFrameProducesValidFrame(): void
    {
        $msg = \Phalanx\WebSocket\Message::text('test');
        $frame = $msg->toFrame();

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame(SWOOLE_WEBSOCKET_OPCODE_TEXT, $frame->opcode);
        $this->assertSame('test', $frame->data);
        $this->assertTrue($frame->finish);
    }

    #[Test]
    public function fromFrameRoundTripsText(): void
    {
        $original = \Phalanx\WebSocket\Message::text('round trip');
        $frame = $original->toFrame();
        $restored = \Phalanx\WebSocket\Message::fromFrame($frame);

        $this->assertSame($original->payload, $restored->payload);
        $this->assertSame($original->opcode, $restored->opcode);
        $this->assertTrue($restored->isText);
    }

    #[Test]
    public function fromFrameCreatesDistinctOwnedMessages(): void
    {
        $first = \Phalanx\WebSocket\Message::fromFrame(\Phalanx\WebSocket\Message::text('first')->toFrame());
        $second = \Phalanx\WebSocket\Message::fromFrame(\Phalanx\WebSocket\Message::text('second')->toFrame());

        $this->assertNotSame(spl_object_id($first), spl_object_id($second));
        $this->assertSame('first', $first->payload);
        $this->assertSame('second', $second->payload);
    }

    #[Test]
    public function fromFrameDecodesCloseCode(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = pack('n', 1001) . 'going away';
        $closeFrame->opcode = SWOOLE_WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = SWOOLE_WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = \Phalanx\WebSocket\Message::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertSame(\Phalanx\WebSocket\CloseCode::GoingAway, $msg->closeCode);
        $this->assertSame('going away', $msg->payload);
    }

    #[Test]
    public function fromFrameHandlesCloseWithoutPayload(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = '';
        $closeFrame->opcode = SWOOLE_WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = SWOOLE_WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = \Phalanx\WebSocket\Message::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertNull($msg->closeCode);
        $this->assertSame('', $msg->payload);
    }

    #[Test]
    public function fromFrameHandlesShortClosePayload(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = "\x03";
        $closeFrame->opcode = SWOOLE_WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = SWOOLE_WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = \Phalanx\WebSocket\Message::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertNull($msg->closeCode);
        $this->assertSame("\x03", $msg->payload);
    }

    #[Test]
    public function fromFrameHandlesUnknownCloseCode(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = pack('n', 9999) . 'olympus';
        $closeFrame->opcode = SWOOLE_WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = SWOOLE_WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = \Phalanx\WebSocket\Message::fromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertNull($msg->closeCode);
        $this->assertSame('olympus', $msg->payload);
    }
}
