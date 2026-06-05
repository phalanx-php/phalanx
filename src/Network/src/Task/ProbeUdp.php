<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Mark\Mark;
use Phalanx\Network\ProbeResult;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\TaskScope;
use Phalanx\System\UdpSocket;
use Phalanx\Task\Scopeable;

final class ProbeUdp implements Scopeable, Recoverable
{
    public RecoveryPlan $recovery {
        get {
            return $this->recoveryPlan();
        }
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
        } catch (Cancelled $e) {
            throw $e;
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

    private function recoveryPlan(): RecoveryPlan
    {
        return RecoveryPlan::failFast(deadline: Mark::s($this->timeoutSeconds + 0.5));
    }
}
