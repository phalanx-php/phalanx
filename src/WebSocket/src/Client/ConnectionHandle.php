<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Client;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Mark\Mark;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Runtime\Memory\ManagedResourceRegistry;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Subscription;
use Phalanx\Stream\Channel;
use Phalanx\Supervisor\TaskHandle;
use Phalanx\Supervisor\WaitReason;
use Phalanx\WebSocket\Runtime\Identity\WebSocketEventSid;
use Swoole\Coroutine\Http\Client as SwooleHttpClient;
use Swoole\WebSocket\Frame;
use Throwable;

/**
 * Public handle to a live WebSocket client session.
 *
 * Owns the inbound message channel, the outbound write queue, and the
 * supervised reader/writer/ping coroutines. Cleanup flows through close(),
 * which is registered as the owning scope's disposer so a timed-out parent
 * cannot leak the underlying socket.
 */
final class ConnectionHandle
{
    private(set) bool $closing = false;

    private(set) bool $closed = false;

    /** computed: reports whether close has started or completed. */
    public bool $isConnected {
        get => !$this->closing && !$this->closed;
    }

    private readonly ManagedResourceRegistry $resources;

    private readonly Channel $inbound;

    private readonly Channel $writes;

    private readonly TaskHandle $readerRun;

    private readonly TaskHandle $writerRun;

    private readonly Subscription $pingSubscription;

    public function __construct(
        ExecutionScope $scope,
        private readonly SwooleHttpClient $client,
        private readonly \Phalanx\WebSocket\Client\Config $config,
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
                            static fn() => $client->recv($config->recvTimeout),
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

                        $message = \Phalanx\WebSocket\Message::fromFrame($frame);

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
                        if (!($message instanceof \Phalanx\WebSocket\Message)) {
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
            Mark::s($config->pingInterval),
            static function () use ($writes): void {
                $writes->tryEmit(\Phalanx\WebSocket\Message::ping());
            },
        );

        $scope->onDispose(static function () use ($self): void {
            $self->close();
        });
    }

    /** @return iterable<\Phalanx\WebSocket\Message> */
    public function messages(): iterable
    {
        yield from $this->inbound->consume();
    }

    public function send(\Phalanx\WebSocket\Message $message): void
    {
        if ($this->closing || $this->closed) {
            throw new \Phalanx\WebSocket\Client\Exception('WebSocket connection is closing or closed.');
        }
        if (!$this->writes->tryEmit($message)) {
            try {
                $this->resources->recordEvent($this->resource, WebSocketEventSid::WriteQueueFull);
            } catch (Cancelled $cancelled) {
                throw $cancelled;
            } catch (Throwable) {
            }

            throw new \Phalanx\WebSocket\Client\Exception('Write queue full; dropping message.');
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

    public function sendJson(mixed $data, int $flags = 0): void
    {
        $this->send(\Phalanx\WebSocket\Message::json($data, $flags));
    }

    public function ping(string $payload = ''): void
    {
        $this->send(\Phalanx\WebSocket\Message::ping($payload));
    }

    public function close(\Phalanx\WebSocket\CloseCode $code = \Phalanx\WebSocket\CloseCode::Normal, string $reason = ''): void
    {
        if ($this->closing) {
            return;
        }
        $this->closing = true;

        $this->writes->tryEmit(\Phalanx\WebSocket\Message::close($code, $reason));

        if (!$this->pingSubscription->cancelled) {
            $this->pingSubscription->cancel();
        }

        $this->writes->complete();
        $this->inbound->complete();

        $this->readerRun->cancel();
        $this->writerRun->cancel();

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
                WebSocketEventSid::ConnectionClosed,
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
            $this->resources->recordEvent($this->resource, WebSocketEventSid::WriteFailed);
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        }
        $this->close(\Phalanx\WebSocket\CloseCode::AbnormalClosure, 'write_failed');
    }
}
