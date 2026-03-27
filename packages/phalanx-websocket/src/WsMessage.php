<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Ratchet\RFC6455\Messaging\Frame;

final class WsMessage
{
    public bool $isText {
        get => $this->opcode === Frame::OP_TEXT;
    }

    public bool $isBinary {
        get => $this->opcode === Frame::OP_BINARY;
    }

    public bool $isClose {
        get => $this->opcode === Frame::OP_CLOSE;
    }

    public bool $isPing {
        get => $this->opcode === Frame::OP_PING;
    }

    public bool $isPong {
        get => $this->opcode === Frame::OP_PONG;
    }

    public function __construct(
        public private(set) string $payload,
        public private(set) int $opcode,
        public private(set) ?WsCloseCode $closeCode = null,
    ) {
    }

    public static function text(string $payload): self
    {
        return new self($payload, Frame::OP_TEXT);
    }

    public static function binary(string $payload): self
    {
        return new self($payload, Frame::OP_BINARY);
    }

    public static function close(
        WsCloseCode $code = WsCloseCode::Normal,
        string $reason = '',
    ): self {
        $payload = pack('n', $code->value) . $reason;

        return new self($payload, Frame::OP_CLOSE, $code);
    }

    public static function ping(string $payload = ''): self
    {
        return new self($payload, Frame::OP_PING);
    }

    public static function pong(string $payload = ''): self
    {
        return new self($payload, Frame::OP_PONG);
    }

    public static function fromFrame(Frame $frame): self
    {
        $opcode = $frame->getOpcode();
        $payload = $frame->getPayload();
        $closeCode = null;

        if ($opcode === Frame::OP_CLOSE && strlen($payload) >= 2) {
            $unpacked = unpack('n', substr($payload, 0, 2));
            if ($unpacked === false) {
                return new self($payload, $opcode);
            }
            $closeCode = WsCloseCode::tryFrom((int) $unpacked[1]);
            $payload = substr($payload, 2);
        }

        return new self($payload, $opcode, $closeCode);
    }

    public function json(bool $assoc = true, int $flags = 0): mixed
    {
        return json_decode($this->payload, $assoc, 512, $flags | JSON_THROW_ON_ERROR);
    }

    public function toFrame(bool $masked = false): Frame
    {
        $frame = new Frame($this->payload, true, $this->opcode);

        if ($masked) {
            $frame->maskPayload();
        }

        return $frame;
    }
}
