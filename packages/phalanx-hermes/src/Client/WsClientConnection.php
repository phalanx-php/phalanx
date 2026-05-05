<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Client;

use Phalanx\Hermes\WsCloseCode;
use Phalanx\Hermes\WsMessage;
use Phalanx\Styx\Channel;
use Phalanx\Suspendable;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Socket\Connector;
use React\Stream\DuplexStreamInterface;

use function React\Promise\Timer\timeout;

final class WsClientConnection
{
    private const string WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-5AB9DC085B7D';

    public bool $isConnected {
        get => $this->transport->isWritable();
    }

    private ?TimerInterface $pingTimer = null;

    private function __construct(
        private DuplexStreamInterface $transport,
        private WsClientCodec $codec,
        private(set) Channel $inbound,
        private WsClientConfig $config,
    ) {
        self::wireTransportEvents($this->transport, $this->codec, $this->inbound, $this);
        self::startPingTimer($this);
    }

    public static function connect(
        Suspendable $scope,
        string $url,
        ?WsClientConfig $config = null,
    ): self {
        $config ??= WsClientConfig::default();
        $parsed = self::parseUrl($url);
        $codec = new WsClientCodec($config->maxMessageSize, $config->maxFrameSize);
        $inbound = new Channel(bufferSize: $config->inboundBufferSize);

        $connector = new Connector();
        $connectTarget = $parsed['host'] . ':' . $parsed['port'];

        if ($parsed['secure']) {
            $connectTarget = 'tls://' . $connectTarget;
        }

        /** @var DuplexStreamInterface $transport */
        $transport = $scope->await(
            timeout($connector->connect($connectTarget), $config->connectTimeout),
        );

        $overflow = self::performHandshake($scope, $transport, $parsed, $config);

        $codec->attach($inbound, self::buildControlHandler($inbound, $codec, $transport));

        $connection = new self($transport, $codec, $inbound, $config);

        // Feed any WebSocket frame data that arrived in the same TCP segment
        // as the 101 response directly to the codec, now that it is attached.
        if ($overflow !== '') {
            $codec->onData($overflow);
        }

        return $connection;
    }

    public function send(WsMessage $msg): void
    {
        if ($this->transport->isWritable()) {
            $this->transport->write($this->codec->encode($msg));
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

    public function close(
        WsCloseCode $code = WsCloseCode::Normal,
        string $reason = '',
    ): void {
        self::cancelPingTimer($this->pingTimer);
        $this->pingTimer = null;

        if (!$this->transport->isWritable()) {
            return;
        }

        try {
            $this->transport->write(
                $this->codec->encode(WsMessage::close($code, $reason)),
            );
        } finally {
            $this->inbound->complete();
            $this->transport->end();
        }
    }

    /**
     * @return array{host: string, port: int, path: string, secure: bool}
     */
    private static function parseUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException("Invalid WebSocket URL: {$url}");
        }

        $scheme = strtolower($parts['scheme'] ?? 'ws');
        $secure = $scheme === 'wss';
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $path = ($parts['path'] ?? '/');

        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return [
            'host' => $parts['host'],
            'port' => $port,
            'path' => $path,
            'secure' => $secure,
        ];
    }

    /**
     * Performs the HTTP upgrade handshake and returns any overflow data
     * (WebSocket frames that arrived in the same TCP segment as the 101 response).
     *
     * @param array{host: string, port: int, path: string, secure: bool} $parsed
     */
    private static function performHandshake(
        Suspendable $scope,
        DuplexStreamInterface $transport,
        array $parsed,
        WsClientConfig $config,
    ): string {
        $key = base64_encode(random_bytes(16));
        $host = $parsed['host'];

        if (($parsed['secure'] && $parsed['port'] !== 443) || (!$parsed['secure'] && $parsed['port'] !== 80)) {
            $host .= ':' . $parsed['port'];
        }

        $request = "GET {$parsed['path']} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "\r\n";

        $transport->write($request);

        $expectedAccept = base64_encode(sha1($key . self::WEBSOCKET_GUID, true));

        $deferred = new Deferred(static function () use ($transport): void {
            $transport->close();
        });

        $responseBuffer = '';

        $transport->on('data', $listener = static function (string $data) use (
            $transport,
            $expectedAccept,
            $deferred,
            &$responseBuffer,
            &$listener,
        ): void {
            $responseBuffer .= $data;

            // Wait until we have the complete HTTP response headers
            $headerEnd = strpos($responseBuffer, "\r\n\r\n");
            if ($headerEnd === false) {
                return;
            }

            // Remove this listener -- handshake is done, codec takes over
            $transport->removeListener('data', $listener);

            $headers = substr($responseBuffer, 0, $headerEnd);
            $overflow = substr($responseBuffer, $headerEnd + 4);

            if (!str_contains($headers, '101')) {
                $deferred->reject(new \RuntimeException(
                    'WebSocket handshake failed: expected 101 Switching Protocols, got: '
                    . strtok($headers, "\r\n"),
                ));

                return;
            }

            // Validate Sec-WebSocket-Accept
            if (preg_match('/Sec-WebSocket-Accept:\s*(.+)/i', $headers, $matches)) {
                $actualAccept = trim($matches[1]);
                if ($actualAccept !== $expectedAccept) {
                    $deferred->reject(new \RuntimeException(
                        'WebSocket handshake failed: Sec-WebSocket-Accept mismatch',
                    ));

                    return;
                }
            } else {
                $deferred->reject(new \RuntimeException(
                    'WebSocket handshake failed: missing Sec-WebSocket-Accept header',
                ));

                return;
            }

            $deferred->resolve($overflow);
        });

        /** @var string */
        return $scope->await(
            timeout($deferred->promise(), $config->connectTimeout),
        );
    }

    private static function wireTransportEvents(
        DuplexStreamInterface $transport,
        WsClientCodec $codec,
        Channel $inbound,
        self $connection,
    ): void {
        $transport->on('data', static function (string $raw) use ($codec): void {
            $codec->onData($raw);
        });

        $transport->on('close', static function () use ($inbound, $connection): void {
            self::cancelPingTimer($connection->pingTimer);
            $connection->pingTimer = null;
            $inbound->complete();
        });

        $transport->on('error', static function (\Throwable $e) use ($inbound, $connection): void {
            self::cancelPingTimer($connection->pingTimer);
            $connection->pingTimer = null;
            $inbound->error($e);
        });
    }

    /**
     * @return callable(WsMessage): void
     */
    private static function buildControlHandler(
        Channel $inbound,
        WsClientCodec $codec,
        DuplexStreamInterface $transport,
    ): callable {
        return static function (WsMessage $control) use ($inbound, $codec, $transport): void {
            if ($control->isPing) {
                $pong = WsMessage::pong($control->payload);
                if ($transport->isWritable()) {
                    $transport->write($codec->encode($pong));
                }

                return;
            }

            if ($control->isClose) {
                $inbound->complete();
                if ($transport->isWritable()) {
                    $transport->end();
                }
            }
        };
    }

    private static function startPingTimer(self $self): void
    {
        if ($self->config->pingInterval <= 0) {
            return;
        }

        $transport = $self->transport;
        $codec = $self->codec;
        $interval = $self->config->pingInterval;

        $self->pingTimer = Loop::addPeriodicTimer(
            $interval,
            static function () use ($transport, $codec): void {
                if ($transport->isWritable()) {
                    $transport->write($codec->encode(WsMessage::ping()));
                }
            },
        );
    }

    private static function cancelPingTimer(?TimerInterface $timer): void
    {
        if ($timer !== null) {
            Loop::cancelTimer($timer);
        }
    }
}
