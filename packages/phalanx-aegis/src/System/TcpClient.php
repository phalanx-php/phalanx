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
 * Wraps OpenSwoole\Coroutine\Client(SWOOLE_SOCK_TCP) under the scope's
 * supervised call() so cancellation flows through scope teardown, the
 * supervisor records the wait, and downstream consumers (Argos ProbePort
 * port scans, future Hermes outbound TCP, custom protocol clients) share
 * one coroutine-aware TCP path.
 */
final class TcpClient
{
    public bool $isConnected {
        get => $this->client->isConnected();
    }

    private readonly Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(SWOOLE_SOCK_TCP);
    }

    public function setOption(string $key, string|int|bool $value): void
    {
        $this->client->set([$key => $value]);
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
