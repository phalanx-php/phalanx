<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Server;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Mark\Mark;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Runtime\Memory\ManagedResourceRegistry;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Subscription;
use Phalanx\Supervisor\TaskHandle;
use Phalanx\Supervisor\WaitReason;
use Phalanx\WebSocket\Runtime\Identity\WebSocketEventSid;
use Swoole\Http\Response as SwooleHttpResponse;
use Swoole\WebSocket\Frame;
use Throwable;

/**
 * Server-side WebSocket session runtime.
 *
 * Bridges an existing {@see \Phalanx\WebSocket\Connection} (the user-facing handle) to an
 * upgraded {@see SwooleHttpResponse} via supervised reader/writer/ping
 * coroutines. Reader feeds {@see \Phalanx\WebSocket\Connection::$inbound}; writer drains
 * {@see \Phalanx\WebSocket\Connection::$outbound} and pushes frames to the Swoole
 * response. Close is idempotent and unregisters from the gateway.
 */
final class Connection
{
    private(set) bool $closing = false;

    private(set) bool $closed = false;

    /** computed: reports whether close has started or completed. */
    public bool $isOpen {
        get => !$this->closing && !$this->closed;
    }

    private readonly TaskHandle $readerRun;

    private readonly TaskHandle $writerRun;

    private readonly Subscription $pingSubscription;

    public function __construct(
        ExecutionScope $scope,
        private readonly SwooleHttpResponse $target,
        private readonly \Phalanx\WebSocket\Config $config,
        private readonly \Phalanx\WebSocket\Connection $connection,
        private readonly ManagedResourceHandle $resource,
        private readonly ManagedResourceRegistry $resources,
        private readonly \Phalanx\WebSocket\Gateway $gateway,
        private readonly string $host,
    ) {
        $gateway->register($connection);

        $target = $this->target;
        $config = $this->config;
        $host = $this->host;
        $bridge = $this->connection;
        $self = $this;

        $this->readerRun = $scope->go(
            static function (ExecutionScope $rs) use ($target, $host, $bridge, $config, $self): void {
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

                        $message = \Phalanx\WebSocket\Message::fromFrame($frame);

                        if (strlen($message->payload) > $config->maxFrameSize) {
                            $self->close(\Phalanx\WebSocket\CloseCode::MessageTooBig);

                            return;
                        }

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
                        if (!($message instanceof \Phalanx\WebSocket\Message)) {
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
            Mark::s($config->pingInterval > 0.0 ? $config->pingInterval : 30.0),
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

    public function close(\Phalanx\WebSocket\CloseCode $code = \Phalanx\WebSocket\CloseCode::Normal, string $reason = ''): void
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

        $this->readerRun->cancel();
        $this->writerRun->cancel();

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
            $this->resources->recordEvent($this->resource, WebSocketEventSid::WriteFailed);
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        }
    }
}
