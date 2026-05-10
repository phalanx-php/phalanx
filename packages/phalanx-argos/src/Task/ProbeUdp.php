<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use Phalanx\Argos\ProbeResult;
use Phalanx\Scope\TaskScope;
use Phalanx\System\UdpSocket;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;

final class ProbeUdp implements Scopeable, HasTimeout
{
    public float $timeout {
        get => $this->timeoutSeconds + 0.5;
    }

    public function __construct(
        private readonly string $ip,
        private readonly int $port,
        private readonly string $payload,
        private readonly float $timeoutSeconds = 2.0,
    ) {
    }

    public function __invoke(TaskScope $scope): ProbeResult
    {
        $client = new UdpSocket();

        try {
            $client->connect($scope, $this->ip, $this->port, $this->timeoutSeconds);
        } catch (\Throwable) {
            return new ProbeResult(
                ip: $this->ip,
                reachable: false,
                method: 'udp',
                port: $this->port,
            );
        }

        $start = hrtime(true);
        $client->send($scope, $this->payload, $this->timeoutSeconds);

        $response = null;
        try {
            $response = $client->recv($scope, $this->timeoutSeconds);
        } finally {
            $client->close();
        }

        $elapsed = (hrtime(true) - $start) / 1e6;

        return new ProbeResult(
            ip: $this->ip,
            reachable: $response !== null,
            latencyMs: $response !== null ? $elapsed : null,
            method: 'udp',
            port: $this->port,
            responseData: $response,
        );
    }
}
