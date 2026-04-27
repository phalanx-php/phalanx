<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Client\Tests\Unit;

use Phalanx\Styx\Channel;
use Phalanx\Hermes\Client\WsClientCodec;
use Phalanx\Hermes\WsMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;

use function React\Async\async;
use function React\Async\await;

final class WsClientCodecTest extends TestCase
{
    #[Test]
    public function encode_produces_masked_frames(): void
    {
        $codec = new WsClientCodec();
        $encoded = $codec->encode(WsMessage::text('hello'));

        // RFC 6455: mask bit is the high bit of the second byte
        $this->assertNotEmpty($encoded);
        $secondByte = ord($encoded[1]);
        $maskBit = ($secondByte >> 7) & 1;
        $this->assertSame(1, $maskBit, 'Client frames must have the mask bit set');
    }

    #[Test]
    public function encode_produces_masked_binary_frames(): void
    {
        $codec = new WsClientCodec();
        $encoded = $codec->encode(WsMessage::binary("\x00\x01\x02"));

        $secondByte = ord($encoded[1]);
        $maskBit = ($secondByte >> 7) & 1;
        $this->assertSame(1, $maskBit);
    }

    #[Test]
    public function encode_produces_masked_ping_frames(): void
    {
        $codec = new WsClientCodec();
        $encoded = $codec->encode(WsMessage::ping('heartbeat'));

        $secondByte = ord($encoded[1]);
        $maskBit = ($secondByte >> 7) & 1;
        $this->assertSame(1, $maskBit);
    }

    #[Test]
    public function decode_parses_unmasked_server_text_frame(): void
    {
        $codec = new WsClientCodec();
        $inbound = new Channel(bufferSize: 8);
        $codec->attach($inbound, static fn() => null);

        // Server sends unmasked frames
        $serverFrame = new Frame('hello from server', true, Frame::OP_TEXT);
        $codec->onData($serverFrame->getContents());

        $received = $this->drainOne($inbound);

        $this->assertTrue($received->isText);
        $this->assertSame('hello from server', $received->payload);
    }

    #[Test]
    public function decode_parses_unmasked_server_binary_frame(): void
    {
        $codec = new WsClientCodec();
        $inbound = new Channel(bufferSize: 8);
        $codec->attach($inbound, static fn() => null);

        $data = random_bytes(32);
        $serverFrame = new Frame($data, true, Frame::OP_BINARY);
        $codec->onData($serverFrame->getContents());

        $received = $this->drainOne($inbound);

        $this->assertTrue($received->isBinary);
        $this->assertSame($data, $received->payload);
    }

    #[Test]
    public function control_callback_fires_for_ping(): void
    {
        $codec = new WsClientCodec();
        $inbound = new Channel(bufferSize: 8);
        $controls = [];

        $codec->attach($inbound, static function (WsMessage $msg) use (&$controls): void {
            $controls[] = $msg;
        });

        $pingFrame = new Frame('', true, Frame::OP_PING);
        $codec->onData($pingFrame->getContents());

        $this->assertCount(1, $controls);
        $this->assertTrue($controls[0]->isPing);
    }

    #[Test]
    public function control_callback_fires_for_close(): void
    {
        $codec = new WsClientCodec();
        $inbound = new Channel(bufferSize: 8);
        $controls = [];

        $codec->attach($inbound, static function (WsMessage $msg) use (&$controls): void {
            $controls[] = $msg;
        });

        $closeFrame = new Frame(pack('n', 1000) . 'bye', true, Frame::OP_CLOSE);
        $codec->onData($closeFrame->getContents());

        $this->assertCount(1, $controls);
        $this->assertTrue($controls[0]->isClose);
    }

    #[Test]
    public function control_callback_fires_for_pong(): void
    {
        $codec = new WsClientCodec();
        $inbound = new Channel(bufferSize: 8);
        $controls = [];

        $codec->attach($inbound, static function (WsMessage $msg) use (&$controls): void {
            $controls[] = $msg;
        });

        $pongFrame = new Frame('', true, Frame::OP_PONG);
        $codec->onData($pongFrame->getContents());

        $this->assertCount(1, $controls);
        $this->assertTrue($controls[0]->isPong);
    }

    #[Test]
    public function ondata_before_attach_is_safe(): void
    {
        $codec = new WsClientCodec();
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
