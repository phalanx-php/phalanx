<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Client;

use OpenSwoole\Coroutine\Http\Client as SwooleHttpClient;
use OpenSwoole\WebSocket\Frame;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Hermes\Runtime\Identity\HermesEventSid;
use Phalanx\Hermes\WsCloseCode;
use Phalanx\Hermes\WsMessage;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Runtime\Memory\ManagedResourceRegistry;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Subscription;
use Phalanx\Styx\Channel;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Supervisor\WaitReason;
use Throwable;

/**
 * Public handle to a live WebSocket client session.
 *
 * Owns the inbound message channel, the outbound write queue, and the
 * supervised reader/writer/ping coroutines. Cleanup flows through close(),
 * which is registered as the owning scope's disposer so a timed-out parent
 * cannot leak the underlying socket.
 */
final class WsClientConnectionHandle
{
    private(set) bool $closing = false;

    private(set) bool $closed = false;

    public bool $isConnected {
        get => !$this->closing && !$this->closed;
    }

    private readonly ManagedResourceRegistry $resources;

    private readonly Channel $inbound;

    private readonly Channel $writes;

    private readonly TaskRun $readerRun;

    private readonly TaskRun $writerRun;

    private readonly Subscription $pingSubscription;

    public function __construct(
        ExecutionScope $scope,
        private readonly SwooleHttpClient $client,
        private readonly WsClientConfig $config,
        private readonly ManagedResourceHandle $resource,
        private readonly string $host,
    ) {
        $this->resources = $scope->runtime->memory->resources;
        $this->inbound = new Channel($config->inboundBufferSize);
        $this->writes = new Channel($config->writeQueueSize);

        $client = $this->client;
        $config = $this->config;
        $host = $this->host;
        $inbound = $this->inbound;
        $writes = $this->writes;
        $self = $this;

        $this->readerRun = $scope->go(
            static function (ExecutionScope $rs) use ($client, $config, $host, $inbound): void {
                try {
                    while (true) {
                        $rs->throwIfCancelled();
                        $frame = $rs->call(
                            static fn(): Frame|bool => $client->recv($config->recvTimeout),
                            WaitReason::wsFrameRead($host),
                        );

                        if ($frame === false) {
                            if (!$client->connected) {
                                return;
                            }
                            continue;
                        }

                        if (!($frame instanceof Frame)) {
                            continue;
                        }

                        $message = WsMessage::fromFrame($frame);

                        $inbound->emit($message);

                        if ($message->isClose) {
                            return;
                        }
                    }
                } catch (Cancelled $cancelled) {
                    throw $cancelled;
                } catch (Throwable $e) {
                    $inbound->error($e);
                } finally {
                    $inbound->complete();
                }
            },
        );

        $this->writerRun = $scope->go(
            static function (ExecutionScope $ws) use ($client, $writes, $host, $self): void {
                try {
                    foreach ($writes->consume() as $message) {
                        $ws->throwIfCancelled();
                        if (!($message instanceof WsMessage)) {
                            continue;
                        }

                        $sent = $ws->call(
                            static fn(): bool => $client->push($message->payload, $message->opcode),
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
            $config->pingInterval,
            static function () use ($writes): void {
                $writes->tryEmit(WsMessage::ping());
            },
        );

        $scope->onDispose(static function () use ($self): void {
            $self->close();
        });
    }

    /** @return iterable<WsMessage> */
    public function messages(): iterable
    {
        yield from $this->inbound->consume();
    }

    public function send(WsMessage $message): void
    {
        if ($this->closing || $this->closed) {
            throw new WsClientException('WebSocket connection is closing or closed.');
        }
        if (!$this->writes->tryEmit($message)) {
            try {
                $this->resources->recordEvent($this->resource, HermesEventSid::WriteQueueFull);
            } catch (Cancelled $cancelled) {
                throw $cancelled;
            } catch (Throwable) {
            }
            throw new WsClientException('Write queue full; dropping message.');
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

    public function sendJson(mixed $data, int $flags = 0): void
    {
        $this->send(WsMessage::json($data, $flags));
    }

    public function ping(string $payload = ''): void
    {
        $this->send(WsMessage::ping($payload));
    }

    public function close(WsCloseCode $code = WsCloseCode::Normal, string $reason = ''): void
    {
        if ($this->closing) {
            return;
        }
        $this->closing = true;

        $this->writes->tryEmit(WsMessage::close($code, $reason));

        if (!$this->pingSubscription->cancelled) {
            $this->pingSubscription->cancel();
        }

        $this->writes->complete();
        $this->inbound->complete();

        if (!$this->readerRun->cancellation->isCancelled) {
            $this->readerRun->cancellation->cancel();
        }
        if (!$this->writerRun->cancellation->isCancelled) {
            $this->writerRun->cancellation->cancel();
        }

        try {
            $this->client->close();
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        }

        $this->closed = true;

        try {
            $this->resources->recordEvent(
                $this->resource,
                HermesEventSid::ConnectionClosed,
                $reason === '' ? 'closed' : $reason,
            );
            $this->resources->close($this->resource, $reason === '' ? 'closed' : $reason);
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        } finally {
            try {
                $this->resources->release($this->resource->id);
            } catch (Cancelled $cancelled) {
                throw $cancelled;
            } catch (Throwable) {
            }
        }
    }

    public function onWriteFailed(): void
    {
        try {
            $this->resources->recordEvent($this->resource, HermesEventSid::WriteFailed);
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        }
        $this->close(WsCloseCode::AbnormalClosure, 'write_failed');
    }
}
