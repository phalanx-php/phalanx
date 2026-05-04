<?php

declare(strict_types=1);

namespace Phalanx\System;

use OpenSwoole\Coroutine\Client;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;
use RuntimeException;

/**
 * Aegis-managed TCP client primitive.
 *
 * Wraps OpenSwoole\Coroutine\Client(SWOOLE_SOCK_TCP[ | SWOOLE_SSL]) under
 * the scope's supervised call() so cancellation flows through scope
 * teardown, the supervisor records the wait, and downstream consumers
 * (Argos ProbePort port scans, future Hermes outbound TCP, custom protocol
 * clients) share one coroutine-aware TCP path.
 *
 * TLS is opt-in at construction via `$tls = true` so the SSL flag is
 * baked into the underlying socket type from the start. SSL options
 * (verify_peer, ca paths, cert/key files, ciphers) are passed via the
 * typed TlsOptions value object, keeping SSL config out of the stringly-
 * typed setOption() surface.
 */
final class TcpClient
{
    public bool $isConnected {
        get => $this->client->isConnected();
    }

    public bool $tls {
        get => $this->tlsEnabled;
    }

    private readonly Client $client;

    private readonly bool $tlsEnabled;

    public function __construct(?Client $client = null, bool $tls = false, ?TlsOptions $tlsOptions = null)
    {
        $this->tlsEnabled = $tls;
        $this->client = $client ?? new Client($tls ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP);
        if ($tls && $tlsOptions !== null) {
            $this->client->set($tlsOptions->toClientOptions());
        }
    }

    public function setOption(string $key, string|int|bool $value): void
    {
        $this->client->set([$key => $value]);
    }

    public function setTlsOptions(TlsOptions $options): void
    {
        if (!$this->tlsEnabled) {
            throw new RuntimeException(
                'TcpClient::setTlsOptions(): TLS not enabled at construction. Pass $tls=true to apply SSL options.',
            );
        }
        $this->client->set($options->toClientOptions());
    }

    public function connect(Suspendable $scope, string $host, int $port, float $timeout = 1.0): bool
    {
        $client = $this->client;
        return $scope->call(
            static fn(): bool => $client->connect($host, $port, $timeout),
            WaitReason::custom("tcp.connect {$host}:{$port}"),
        );
    }

    public function send(Suspendable $scope, string $payload, float $timeout = 1.0): int
    {
        $client = $this->client;
        $written = $scope->call(
            static fn(): bool|int => $client->send($payload, $timeout),
            WaitReason::custom('tcp.send'),
        );
        if ($written === false) {
            throw new RuntimeException(
                "TcpClient::send failed (errCode={$client->errCode}, errMsg={$client->errMsg})",
            );
        }
        return (int) $written;
    }

    public function recv(Suspendable $scope, float $timeout = 1.0): ?string
    {
        $client = $this->client;
        $payload = $scope->call(
            static fn(): bool|string => $client->recv($timeout),
            WaitReason::custom('tcp.recv'),
        );
        return is_string($payload) ? $payload : null;
    }

    public function close(): void
    {
        if ($this->client->isConnected()) {
            $this->client->close();
        }
    }
}
