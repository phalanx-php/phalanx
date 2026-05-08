<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Server;

use OpenSwoole\Http\Response as SwooleHttpResponse;
use OpenSwoole\WebSocket\Frame;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Hermes\Runtime\Identity\HermesEventSid;
use Phalanx\Hermes\WsCloseCode;
use Phalanx\Hermes\WsConfig;
use Phalanx\Hermes\WsConnection;
use Phalanx\Hermes\WsGateway;
use Phalanx\Hermes\WsMessage;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Runtime\Memory\ManagedResourceRegistry;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Subscription;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Supervisor\WaitReason;
use Throwable;

/**
 * Server-side WebSocket session runtime.
 *
 * Bridges an existing {@see WsConnection} (the user-facing handle) to an
 * upgraded {@see SwooleHttpResponse} via supervised reader/writer/ping
 * coroutines. Reader feeds {@see WsConnection::$inbound}; writer drains
 * {@see WsConnection::$outbound} and pushes frames to the OpenSwoole
 * response. Close is idempotent and unregisters from the gateway.
 */
final class WsServerConnection
{
    private readonly TaskRun $readerRun;

    private readonly TaskRun $writerRun;

    private readonly Subscription $pingSubscription;

    private(set) bool $closing = false;

    private(set) bool $closed = false;

    public bool $isOpen {
        get => !$this->closing && !$this->closed;
    }

    public function __construct(
        ExecutionScope $scope,
        private readonly SwooleHttpResponse $target,
        private readonly WsConfig $config,
        private readonly WsConnection $connection,
        private readonly ManagedResourceHandle $resource,
        private readonly ManagedResourceRegistry $resources,
        private readonly WsGateway $gateway,
        private readonly string $host,
    ) {
        $gateway->register($connection);

        $target = $this->target;
        $config = $this->config;
        $host = $this->host;
        $bridge = $this->connection;
        $self = $this;

        $this->readerRun = $scope->go(
            static function (ExecutionScope $rs) use ($target, $host, $bridge): void {
                try {
                    while (true) {
                        $rs->throwIfCancelled();
                        $frame = $rs->call(
                            static fn(): Frame|bool|string => $target->recv(1.0),
                            WaitReason::wsFrameRead($host),
                        );

                        if ($frame === false) {
                            return;
                        }

                        if (!($frame instanceof Frame)) {
                            continue;
                        }

                        $message = WsMessage::fromFrame($frame);
                        $bridge->inbound->emit($message);

                        if ($message->isClose) {
                            return;
                        }
                    }
                } catch (Cancelled $cancelled) {
                    throw $cancelled;
                } catch (Throwable $e) {
                    $bridge->inbound->error($e);
                } finally {
                    $bridge->inbound->complete();
                }
            },
        );

        $this->writerRun = $scope->go(
            static function (ExecutionScope $ws) use ($target, $bridge, $host, $self): void {
                try {
                    foreach ($bridge->outbound->consume() as $message) {
                        $ws->throwIfCancelled();
                        if (!($message instanceof WsMessage)) {
                            continue;
                        }

                        $sent = $ws->call(
                            static fn(): bool => $target->push($message->payload, $message->opcode),
                            WaitReason::wsFrameWrite($host, strlen($message->payload)),
                        );

                        if ($sent !== true) {
                            $self->onWriteFailed();
                            return;
                        }
                    }
                } catch (Cancelled $cancelled) {
                    throw $cancelled;
                } catch (Throwable) {
                    $self->onWriteFailed();
                }
            },
        );

        $this->pingSubscription = $scope->periodic(
            $config->pingInterval > 0.0 ? $config->pingInterval : 30.0,
            static function () use ($bridge): void {
                if ($bridge->isOpen) {
                    $bridge->ping();
                }
            },
        );

        $scope->onDispose(static function () use ($self): void {
            $self->close();
        });
    }

    public function close(WsCloseCode $code = WsCloseCode::Normal, string $reason = ''): void
    {
        if ($this->closing) {
            return;
        }
        $this->closing = true;

        if (!$this->pingSubscription->cancelled) {
            $this->pingSubscription->cancel();
        }

        if ($this->connection->isOpen) {
            try {
                $this->connection->close($code, $reason);
            } catch (Cancelled $cancelled) {
                throw $cancelled;
            } catch (Throwable) {
            }
        } else {
            $this->connection->inbound->complete();
            $this->connection->outbound->complete();
        }

        if (!$this->readerRun->cancellation->isCancelled) {
            $this->readerRun->cancellation->cancel();
        }
        if (!$this->writerRun->cancellation->isCancelled) {
            $this->writerRun->cancellation->cancel();
        }

        try {
            $this->target->close();
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        }

        try {
            $this->gateway->unregister($this->connection);
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        }

        $this->closed = true;
    }

    public function onWriteFailed(): void
    {
        try {
            $this->resources->recordEvent($this->resource, HermesEventSid::WriteFailed);
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        }
    }
}
