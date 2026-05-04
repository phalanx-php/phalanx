<?php

declare(strict_types=1);

namespace Phalanx\System;

use OpenSwoole\Coroutine\Client;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;
use RuntimeException;

/**
 * Aegis-managed UDP socket primitive.
 *
 * Wraps OpenSwoole\Coroutine\Client(SWOOLE_SOCK_UDP) under the scope's
 * supervised call() so cancellation flows through the scope's
 * cancellation token, the supervisor records the wait, and downstream
 * consumers (Argos ProbeUdp/WakeHost, future Hermes UDP servers, mDNS
 * protocol implementations) share one coroutine-aware UDP path.
 *
 * Lifecycle:
 *   - connect() must be called before send/recv. It binds and registers
 *     the connection target.
 *   - For one-shot probes, instantiate, connect, send/recv, close().
 *   - For broadcast (e.g. Wake-on-LAN), enable the broadcast flag via
 *     setBroadcast(true) before send.
 */
final class UdpSocket
{
    public bool $isConnected {
        get => $this->client->isConnected();
    }

    private readonly Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(SWOOLE_SOCK_UDP);
    }

    public function setOption(string $key, string|int|bool $value): void
    {
        $this->client->set([$key => $value]);
    }

    public function setBroadcast(bool $enabled): void
    {
        $this->client->set(['enable_broadcast' => $enabled]);
    }

    public function connect(Suspendable $scope, string $host, int $port, float $timeout = 1.0): void
    {
        $client = $this->client;
        $ok = $scope->call(
            static fn(): bool => $client->connect($host, $port, $timeout),
            WaitReason::custom("udp.connect {$host}:{$port}"),
        );
        if (!$ok) {
            throw new RuntimeException(
                "UdpSocket::connect failed: {$host}:{$port} (errCode={$client->errCode}, errMsg={$client->errMsg})",
            );
        }
    }

    public function send(Suspendable $scope, string $payload, float $timeout = 1.0): int
    {
        $client = $this->client;
        $written = $scope->call(
            static fn(): bool|int => $client->send($payload, $timeout),
            WaitReason::custom('udp.send'),
        );
        if ($written === false) {
            throw new RuntimeException(
                "UdpSocket::send failed (errCode={$client->errCode}, errMsg={$client->errMsg})",
            );
        }
        return (int) $written;
    }

    public function recv(Suspendable $scope, float $timeout = 1.0): ?string
    {
        $client = $this->client;
        $payload = $scope->call(
            static fn(): bool|string => $client->recv($timeout),
            WaitReason::custom('udp.recv'),
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
