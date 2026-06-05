<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Emitter;
use Phalanx\Stream\ScopedStream;

final class WsConnection
{
    private(set) string $id;

    public bool $isOpen {
        get => $this->outbound->isOpen;
    }

    private(set) Channel $inbound;
    private(set) Channel $outbound;
    private ?Emitter $inboundEmitter = null;

    public function __construct(
        string $id,
        int $inboundBuffer = 32,
        int $outboundBuffer = 64,
    ) {
        $this->id = $id;
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

        $this->outbound->tryEmit(WsMessage::close($code, $reason));
        $this->outbound->complete();
        $this->inbound->complete();
    }

    public function stream(ExecutionScope $scope): ScopedStream
    {
        if ($this->inboundEmitter === null) {
            $inbound = $this->inbound;
            $this->inboundEmitter = Emitter::produce(static function (
                Channel $ch,
                ExecutionScope $ctx,
            ) use (
                $inbound,
            ): void {
                foreach ($inbound->consume() as $msg) {
                    $ctx->throwIfCancelled();
                    $ch->emit($msg);
                }
            });
        }

        return ScopedStream::from($scope, $this->inboundEmitter);
    }
}
