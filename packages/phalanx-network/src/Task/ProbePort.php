<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\ExecutionScope;
use Phalanx\Network\ProbeResult;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;
use React\Socket\Connector;

final class ProbePort implements Executable, HasTimeout
{
    public float $timeout { get => $this->timeoutSeconds + 0.5; }

    public function __construct(
        private readonly string $ip,
        private readonly int $port,
        private readonly float $timeoutSeconds = 2.0,
    ) {}

    public function __invoke(ExecutionScope $scope): ProbeResult
    {
        $connector = $scope->service(Connector::class);
        $start = hrtime(true);

        $uri = sprintf('tcp://%s:%d', $this->ip, $this->port);

        try {
            $conn = $scope->await($connector->connect($uri));
            $elapsed = (hrtime(true) - $start) / 1e6;
            $conn->close();

            return new ProbeResult(
                ip: $this->ip,
                reachable: true,
                latencyMs: $elapsed,
                method: 'tcp',
                port: $this->port,
            );
        } catch (\RuntimeException) {
            return new ProbeResult(
                ip: $this->ip,
                reachable: false,
                method: 'tcp',
                port: $this->port,
            );
        }
    }
}
