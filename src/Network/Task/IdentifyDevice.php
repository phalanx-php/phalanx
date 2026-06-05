<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\Network\Host;
use Phalanx\Network\ProbeResult;
use Phalanx\Mark\Mark;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class IdentifyDevice implements Executable, Recoverable
{
    public RecoveryPlan $recovery {
        get => RecoveryPlan::failFast(deadline: Mark::s(15.0));
    }

    /** @param list<int> $tcpPorts */
    public function __construct(
        private readonly string $ip,
        private readonly array $tcpPorts = [22, 80, 443, 8080, 8443],
        private readonly float $perPortTimeout = 1.0,
    ) {
    }

    public function __invoke(ExecutionScope $scope): Host
    {
        ['ping' => $ping, 'ports' => $openPorts] = $scope->concurrent(
            ping: new PingHost($this->ip, 2.0),
            ports: new ScanPorts($this->ip, $this->tcpPorts, $this->perPortTimeout, count($this->tcpPorts)),
        );

        $services = array_values(array_map(
            static fn(ProbeResult $r): string => "tcp/{$r->port}",
            $openPorts,
        ));

        return new Host(
            ip: $this->ip,
            services: $services,
            metadata: [
                'reachable' => $ping->reachable,
                'latencyMs' => $ping->latencyMs,
            ],
        );
    }
}
