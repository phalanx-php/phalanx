<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Swoole\WebSocket\Frame;

final class Message
{
    public bool $isText {
        get => $this->opcode === SWOOLE_WEBSOCKET_OPCODE_TEXT;
    }

    public bool $isBinary {
        get => $this->opcode === SWOOLE_WEBSOCKET_OPCODE_BINARY;
    }

    public bool $isClose {
        get => $this->opcode === SWOOLE_WEBSOCKET_OPCODE_CLOSE;
    }

    public bool $isPing {
        get => $this->opcode === SWOOLE_WEBSOCKET_OPCODE_PING;
    }

    public bool $isPong {
        get => $this->opcode === SWOOLE_WEBSOCKET_OPCODE_PONG;
    }

    public function __construct(
        private(set) string $payload,
        private(set) int $opcode,
        private(set) ?\Phalanx\WebSocket\CloseCode $closeCode = null,
    ) {
    }

    public static function text(string $payload): self
    {
        return new self($payload, SWOOLE_WEBSOCKET_OPCODE_TEXT);
    }

    public static function binary(string $payload): self
    {
        return new self($payload, SWOOLE_WEBSOCKET_OPCODE_BINARY);
    }

    public static function close(
        \Phalanx\WebSocket\CloseCode $code = \Phalanx\WebSocket\CloseCode::Normal,
        string $reason = '',
    ): self {
        $payload = pack('n', $code->value) . $reason;

        return new self($payload, SWOOLE_WEBSOCKET_OPCODE_CLOSE, $code);
    }

    public static function ping(string $payload = ''): self
    {
        return new self($payload, SWOOLE_WEBSOCKET_OPCODE_PING);
    }

    public static function pong(string $payload = ''): self
    {
        return new self($payload, SWOOLE_WEBSOCKET_OPCODE_PONG);
    }

    public static function json(mixed $data, int $flags = 0): self
    {
        return self::text(json_encode($data, $flags | JSON_THROW_ON_ERROR));
    }

    public static function fromFrame(Frame $frame): self
    {
        [$payload, $opcode, $closeCode] = self::parseFrame($frame);

        return new self($payload, $opcode, $closeCode);
    }

    public function decode(bool $assoc = true, int $flags = 0): mixed
    {
        return json_decode($this->payload, $assoc, 512, $flags | JSON_THROW_ON_ERROR);
    }

    public function toFrame(): Frame
    {
        $frame = new Frame();
        $frame->data = $this->payload;
        $frame->opcode = $this->opcode;
        $frame->flags = SWOOLE_WEBSOCKET_FLAG_FIN;
        $frame->finish = true;

        return $frame;
    }

    /** @return array{string, int, ?\Phalanx\WebSocket\CloseCode} */
    private static function parseFrame(Frame $frame): array
    {
        $opcode = $frame->opcode;
        $payload = $frame->data;
        $closeCode = null;

        if ($opcode === SWOOLE_WEBSOCKET_OPCODE_CLOSE && strlen((string) $payload) >= 2) {
            $unpacked = unpack('n', substr((string) $payload, 0, 2));
            if ($unpacked !== false) {
                $closeCode = \Phalanx\WebSocket\CloseCode::tryFrom((int) $unpacked[1]);
                $payload = substr((string) $payload, 2);
            }
        }

        return [$payload, $opcode, $closeCode];
    }
}
