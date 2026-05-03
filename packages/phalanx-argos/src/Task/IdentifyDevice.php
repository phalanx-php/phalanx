<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use Phalanx\ExecutionScope;
use Phalanx\Argos\Host;
use Phalanx\Argos\ProbeResult;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;

final class IdentifyDevice implements Executable, HasTimeout
{
    public float $timeout { get => 15.0; }

    /** @param list<int> $tcpPorts */
    public function __construct(
        private readonly string $ip,
        private readonly array $tcpPorts = [22, 80, 443, 8080, 8443],
        private readonly float $perPortTimeout = 1.0,
    ) {}

    public function __invoke(ExecutionScope $scope): Host
    {
        [$ping, $openPorts] = $scope->concurrent(...[
            'ping' => new PingHost($this->ip, 2.0),
            'ports' => new ScanPorts($this->ip, $this->tcpPorts, $this->perPortTimeout, count($this->tcpPorts)),
        ]);

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
