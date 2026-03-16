<?php

declare(strict_types=1);

namespace Convoy\WebSocket;

use Convoy\ExecutionScope;
use Convoy\Stream\Channel;
use Convoy\Stream\Emitter;
use Convoy\Stream\Contract\StreamContext;
use Convoy\Stream\ScopedStream;

final class WsConnection
{
    public string $id {
        get => $this->connectionId;
    }

    public bool $isOpen {
        get => $this->outbound->isOpen;
    }

    private(set) Channel $inbound;
    private(set) Channel $outbound;

    public function __construct(
        private readonly string $connectionId,
        int $inboundBuffer = 32,
        int $outboundBuffer = 64,
    ) {
        $this->inbound = new Channel(bufferSize: $inboundBuffer);
        $this->outbound = new Channel(bufferSize: $outboundBuffer);
    }

    public function send(WsMessage $msg): void
    {
        if ($this->outbound->isOpen) {
            $this->outbound->emit($msg);
        }
    }

    public function sendText(string $payload): void
    {
        $this->send(WsMessage::text($payload));
    }

    public function sendBinary(string $payload): void
    {
        $this->send(WsMessage::binary($payload));
    }

    public function ping(string $payload = ''): void
    {
        $this->send(WsMessage::ping($payload));
    }

    public function close(
        WsCloseCode $code = WsCloseCode::Normal,
        string $reason = '',
    ): void {
        if (!$this->outbound->isOpen) {
            return;
        }

        try {
            $this->outbound->emit(WsMessage::close($code, $reason));
        } finally {
            $this->outbound->complete();
            $this->inbound->complete();
        }
    }

    public function stream(ExecutionScope $scope): ScopedStream
    {
        $inbound = $this->inbound;

        return ScopedStream::from(
            $scope,
            Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($inbound): void {
                foreach ($inbound->consume() as $msg) {
                    $ctx->throwIfCancelled();
                    $ch->emit($msg);
                }
            }),
        );
    }
}
