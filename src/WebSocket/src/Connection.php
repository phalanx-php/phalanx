<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Emitter;
use Phalanx\Stream\Scoped;

final class Connection
{
    public bool $isOpen {
        get => $this->outbound->isOpen;
    }

    private(set) Channel $inbound;
    private(set) Channel $outbound;
    private ?Emitter $inboundEmitter = null;

    public function __construct(
        private(set) string $id,
        int $inboundBuffer = 32,
        int $outboundBuffer = 64,
    ) {
        $this->inbound = new Channel(bufferSize: $inboundBuffer);
        $this->outbound = new Channel(bufferSize: $outboundBuffer);
    }

    public function send(\Phalanx\WebSocket\Message $msg): void
    {
        if ($this->outbound->isOpen) {
            $this->outbound->emit($msg);
        }
    }

    public function sendText(string $payload): void
    {
        $this->send(\Phalanx\WebSocket\Message::text($payload));
    }

    public function sendBinary(string $payload): void
    {
        $this->send(\Phalanx\WebSocket\Message::binary($payload));
    }

    public function ping(string $payload = ''): void
    {
        $this->send(\Phalanx\WebSocket\Message::ping($payload));
    }

    public function close(
        \Phalanx\WebSocket\CloseCode $code = \Phalanx\WebSocket\CloseCode::Normal,
        string $reason = '',
    ): void {
        if (!$this->outbound->isOpen) {
            return;
        }

        $this->outbound->tryEmit(\Phalanx\WebSocket\Message::close($code, $reason));
        $this->outbound->complete();
        $this->inbound->complete();
    }

    public function stream(ExecutionScope $scope): Scoped
    {
        if ($this->inboundEmitter === null) {
            $inbound = $this->inbound;
            $this->inboundEmitter = Emitter::produce(static function (
                ExecutionScope $ctx,
                Channel $ch,
            ) use (
                $inbound,
            ): void {
                foreach ($inbound->consume() as $msg) {
                    $ctx->throwIfCancelled();
                    $ch->emit($msg);
                }
            });
        }

        return Scoped::from($scope, $this->inboundEmitter);
    }
}
