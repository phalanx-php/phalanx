<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Unit;

use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server as WebSocketServer;
use Phalanx\Hermes\WsCloseCode;
use Phalanx\Hermes\WsMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsMessageResetTest extends TestCase
{
    #[Test]
    public function resetUpdatesAllProperties(): void
    {
        $msg = WsMessage::text('apollo');

        $msg->reset('poseidon', WebSocketServer::WEBSOCKET_OPCODE_BINARY);

        $this->assertSame('poseidon', $msg->payload);
        $this->assertSame(WebSocketServer::WEBSOCKET_OPCODE_BINARY, $msg->opcode);
        $this->assertNull($msg->closeCode);
        $this->assertTrue($msg->isBinary);
        $this->assertFalse($msg->isText);
    }

    #[Test]
    public function resetSetsCloseCode(): void
    {
        $msg = WsMessage::text('zeus');

        $msg->reset('', WebSocketServer::WEBSOCKET_OPCODE_CLOSE, WsCloseCode::Normal);

        $this->assertTrue($msg->isClose);
        $this->assertSame(WsCloseCode::Normal, $msg->closeCode);
    }

    #[Test]
    public function resetFromFrameHandlesTextFrame(): void
    {
        $original = WsMessage::text('leonidas');
        $frame = $original->toFrame();

        $msg = WsMessage::text('placeholder');
        $msg->resetFromFrame($frame);

        $this->assertSame('leonidas', $msg->payload);
        $this->assertSame(WebSocketServer::WEBSOCKET_OPCODE_TEXT, $msg->opcode);
        $this->assertNull($msg->closeCode);
        $this->assertTrue($msg->isText);
    }

    #[Test]
    public function resetFromFrameHandlesBinaryFrame(): void
    {
        $data = random_bytes(16);
        $original = WsMessage::binary($data);
        $frame = $original->toFrame();

        $msg = WsMessage::text('placeholder');
        $msg->resetFromFrame($frame);

        $this->assertSame($data, $msg->payload);
        $this->assertTrue($msg->isBinary);
        $this->assertNull($msg->closeCode);
    }

    #[Test]
    public function resetFromFrameParsesCloseFrame(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = pack('n', 1001) . 'thermopylae';
        $closeFrame->opcode = WebSocketServer::WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = WebSocketServer::WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = WsMessage::text('placeholder');
        $msg->resetFromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertSame(WsCloseCode::GoingAway, $msg->closeCode);
        $this->assertSame('thermopylae', $msg->payload);
    }

    #[Test]
    public function resetFromFrameWithShortClosePayload(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = "\x03";
        $closeFrame->opcode = WebSocketServer::WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = WebSocketServer::WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = WsMessage::text('placeholder');
        $msg->resetFromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertNull($msg->closeCode);
        $this->assertSame("\x03", $msg->payload);
    }

    #[Test]
    public function resetFromFrameWithUnknownCloseCode(): void
    {
        $closeFrame = new Frame();
        $closeFrame->data = pack('n', 9999) . 'olympus';
        $closeFrame->opcode = WebSocketServer::WEBSOCKET_OPCODE_CLOSE;
        $closeFrame->flags = WebSocketServer::WEBSOCKET_FLAG_FIN;
        $closeFrame->finish = true;

        $msg = WsMessage::text('placeholder');
        $msg->resetFromFrame($closeFrame);

        $this->assertTrue($msg->isClose);
        $this->assertNull($msg->closeCode);
        $this->assertSame('olympus', $msg->payload);
    }

    #[Test]
    public function resetFromFrameNullsCloseCodeOnNonCloseFrame(): void
    {
        $msg = WsMessage::close(WsCloseCode::Normal, 'done');

        $textFrame = WsMessage::text('sparta')->toFrame();
        $msg->resetFromFrame($textFrame);

        $this->assertTrue($msg->isText);
        $this->assertNull($msg->closeCode);
        $this->assertSame('sparta', $msg->payload);
    }

    #[Test]
    public function multipleResetsStable(): void
    {
        $msg = WsMessage::text('initial');

        $values = [
            ['agora', WebSocketServer::WEBSOCKET_OPCODE_TEXT],
            ['marathon', WebSocketServer::WEBSOCKET_OPCODE_BINARY],
            ['polis', WebSocketServer::WEBSOCKET_OPCODE_TEXT],
            ['achilles', WebSocketServer::WEBSOCKET_OPCODE_BINARY],
            ['hoplite', WebSocketServer::WEBSOCKET_OPCODE_TEXT],
            ['aspis', WebSocketServer::WEBSOCKET_OPCODE_BINARY],
            ['doru', WebSocketServer::WEBSOCKET_OPCODE_TEXT],
            ['sarissa', WebSocketServer::WEBSOCKET_OPCODE_BINARY],
            ['pericles', WebSocketServer::WEBSOCKET_OPCODE_TEXT],
            ['aristotle', WebSocketServer::WEBSOCKET_OPCODE_BINARY],
        ];

        foreach ($values as [$payload, $opcode]) {
            $msg->reset($payload, $opcode);
            $this->assertSame($payload, $msg->payload);
            $this->assertSame($opcode, $msg->opcode);
            $this->assertNull($msg->closeCode);
        }
    }

    #[Test]
    public function splObjectIdPreservedAcrossResets(): void
    {
        $msg = WsMessage::text('hestia');
        $idBefore = spl_object_id($msg);

        $msg->reset('dionysus', WebSocketServer::WEBSOCKET_OPCODE_BINARY);
        $msg->reset('ares', WebSocketServer::WEBSOCKET_OPCODE_TEXT);

        $this->assertSame($idBefore, spl_object_id($msg));
    }
}
