<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Client;

use Phalanx\Styx\Channel;
use Phalanx\Hermes\WsMessage;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;

final class WsClientCodec
{
    private ?MessageBuffer $buffer = null;

    public function __construct(
        private readonly int $maxMessageSize = 65536,
        private readonly int $maxFrameSize = 65536,
    ) {
    }

    public function attach(Channel $inbound, callable $onControl): void
    {
        // Client-mode: expectMask false because the server sends unmasked frames
        $this->buffer = new MessageBuffer(
            new CloseFrameChecker(),
            static function (MessageInterface $message) use ($inbound): void {
                $opcode = $message->isBinary() ? Frame::OP_BINARY : Frame::OP_TEXT;
                $inbound->emit(new WsMessage($message->getPayload(), $opcode));
            },
            static function (Frame $frame) use ($onControl): void {
                $onControl(WsMessage::fromFrame($frame));
            },
            expectMask: false,
            maxMessagePayloadSize: $this->maxMessageSize,
            maxFramePayloadSize: $this->maxFrameSize,
        );
    }

    public function onData(string $raw): void
    {
        $this->buffer?->onData($raw);
    }

    /**
     * Encode a message as a masked client frame (RFC 6455: client MUST mask).
     */
    public function encode(WsMessage $msg): string
    {
        return $msg->toFrame(masked: true)->getContents();
    }
}
