<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use Phalanx\Argos\ProbeResult;
use Phalanx\Scope\TaskScope;
use Phalanx\System\TcpClient;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Scopeable;

final class ProbePort implements Scopeable, HasTimeout
{
    public float $timeout {
        get => $this->timeoutSeconds + 0.5;
    }

    public function __construct(
        private readonly string $ip,
        private readonly int $port,
        private readonly float $timeoutSeconds = 2.0,
    ) {
    }

    public function __invoke(TaskScope $scope): ProbeResult
    {
        $client = new TcpClient();
        $start = hrtime(true);

        $reachable = $client->connect($scope, $this->ip, $this->port, $this->timeoutSeconds);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $client->close();

        return new ProbeResult(
            ip: $this->ip,
            reachable: $reachable,
            latencyMs: $reachable ? $elapsed : null,
            method: 'tcp',
            port: $this->port,
        );
    }
}
