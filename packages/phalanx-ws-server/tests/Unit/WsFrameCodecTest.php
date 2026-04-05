<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\Stream\Channel;
use Phalanx\WebSocket\WsFrameCodec;
use Phalanx\WebSocket\WsMessage;
use Ratchet\RFC6455\Messaging\Frame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function React\Async\async;
use function React\Async\await;

final class WsFrameCodecTest extends TestCase
{
    #[Test]
    public function encode_produces_non_empty_wire_bytes(): void
    {
        $codec = new WsFrameCodec();

        $this->assertNotEmpty($codec->encode(WsMessage::text('hello')));
        $this->assertNotEmpty($codec->encode(WsMessage::binary("\x00\x01")));
        $this->assertNotEmpty($codec->encode(WsMessage::ping()));
    }

    #[Test]
    public function decode_emits_text_message_to_channel(): void
    {
        $codec = new WsFrameCodec();
        $inbound = new Channel(bufferSize: 8);

        $codec->attach($inbound, static fn() => null);

        $clientFrame = new Frame('world', true, Frame::OP_TEXT);
        $clientFrame->maskPayload();
        $codec->onData($clientFrame->getContents());

        $received = $this->drainOne($inbound);

        $this->assertTrue($received->isText);
        $this->assertSame('world', $received->payload);
    }

    #[Test]
    public function decode_routes_ping_to_control_callback(): void
    {
        $codec = new WsFrameCodec();
        $inbound = new Channel(bufferSize: 8);
        $controls = [];

        $codec->attach($inbound, static function (WsMessage $msg) use (&$controls): void {
            $controls[] = $msg;
        });

        $pingFrame = new Frame('', true, Frame::OP_PING);
        $pingFrame->maskPayload();
        $codec->onData($pingFrame->getContents());

        $this->assertCount(1, $controls);
        $this->assertTrue($controls[0]->isPing);
    }

    #[Test]
    public function decode_routes_close_to_control_callback(): void
    {
        $codec = new WsFrameCodec();
        $inbound = new Channel(bufferSize: 8);
        $controls = [];

        $codec->attach($inbound, static function (WsMessage $msg) use (&$controls): void {
            $controls[] = $msg;
        });

        $closeFrame = new Frame(pack('n', 1000) . 'bye', true, Frame::OP_CLOSE);
        $closeFrame->maskPayload();
        $codec->onData($closeFrame->getContents());

        $this->assertCount(1, $controls);
        $this->assertTrue($controls[0]->isClose);
    }

    #[Test]
    public function round_trip_via_masked_frame(): void
    {
        $codec = new WsFrameCodec();
        $inbound = new Channel(bufferSize: 8);
        $codec->attach($inbound, static fn() => null);

        $original = WsMessage::text('round trip test');

        $clientFrame = new Frame($original->payload, true, $original->opcode);
        $clientFrame->maskPayload();
        $codec->onData($clientFrame->getContents());

        $received = $this->drainOne($inbound);

        $this->assertSame($original->payload, $received->payload);
        $this->assertSame($original->opcode, $received->opcode);
        $this->assertTrue($received->isText);
    }

    #[Test]
    public function round_trip_binary_message(): void
    {
        $codec = new WsFrameCodec();
        $inbound = new Channel(bufferSize: 8);
        $codec->attach($inbound, static fn() => null);

        $data = random_bytes(32);
        $clientFrame = new Frame($data, true, Frame::OP_BINARY);
        $clientFrame->maskPayload();
        $codec->onData($clientFrame->getContents());

        $received = $this->drainOne($inbound);

        $this->assertTrue($received->isBinary);
        $this->assertSame($data, $received->payload);
    }

    #[Test]
    public function ondata_before_attach_is_safe(): void
    {
        $codec = new WsFrameCodec();
        $codec->onData('garbage');

        $this->assertTrue(true);
    }

    private function drainOne(Channel $channel): WsMessage
    {
        return await(async(static function () use ($channel): WsMessage {
            foreach ($channel->consume() as $msg) {
                return $msg;
            }
            throw new \RuntimeException('No message received');
        })());
    }
}
