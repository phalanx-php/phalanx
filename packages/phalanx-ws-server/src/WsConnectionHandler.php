<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\ExecutionScope;
use Phalanx\Http\RouteParams;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Stream\DuplexStreamInterface;

use function React\Async\async;

final readonly class WsConnectionHandler
{
    public function __construct(
        private Scopeable|Executable $pump,
        private WsConfig $config,
        private WsGateway $gateway,
    ) {
    }

    public function handle(
        ExecutionScope $scope,
        DuplexStreamInterface $transport,
        ServerRequestInterface $request,
        RouteParams $params,
    ): void {
        $codec = new WsFrameCodec($this->config->maxMessageSize, $this->config->maxFrameSize);
        $conn = new WsConnection(bin2hex(random_bytes(16)));

        $this->gateway->register($conn);

        $wsScope = new WsScope($scope, $conn, $this->config, $request, $params);

        $codec->attach($conn->inbound, static function (WsMessage $control) use ($conn, $codec, $transport): void {
            if ($control->isPing) {
                $pong = WsMessage::pong($control->payload);
                $transport->write($codec->encode($pong));
                return;
            }

            if ($control->isClose) {
                $conn->close($control->closeCode ?? WsCloseCode::Normal);
            }
        });

        $transport->on('data', static function (string $raw) use ($codec): void {
            $codec->onData($raw);
        });

        $drainFn = async(static function () use ($conn, $codec, $transport): void {
            foreach ($conn->outbound->consume() as $msg) {
                assert($msg instanceof WsMessage);
                if (!$transport->isWritable()) {
                    break;
                }
                $transport->write($codec->encode($msg));
            }
        });
        $drainFn();

        $pingTimer = null;
        if ($this->config->pingInterval > 0) {
            $pingTimer = Loop::addPeriodicTimer(
                $this->config->pingInterval,
                static function () use ($conn, $codec, $transport): void {
                    if ($conn->isOpen && $transport->isWritable()) {
                        $transport->write($codec->encode(WsMessage::ping()));
                    }
                },
            );
        }

        $gateway = $this->gateway;

        $scope->onDispose(static function () use ($conn, $pingTimer, $gateway, $transport): void {
            if ($pingTimer instanceof TimerInterface) {
                Loop::cancelTimer($pingTimer);
            }

            if ($conn->isOpen) {
                $conn->close(WsCloseCode::GoingAway, 'Server shutting down');
            }

            $gateway->unregister($conn);

            if ($transport->isWritable()) {
                $transport->end();
            }
        });

        $transport->on('close', static function () use ($conn, $scope): void {
            $conn->inbound->complete();
            $conn->outbound->complete();
            $scope->dispose();
        });

        $transport->on('error', static function (\Throwable $e) use ($conn, $scope): void {
            $conn->inbound->error($e);
            $conn->outbound->complete();
            $scope->dispose();
        });

        ($this->pump)($wsScope);
    }
}
