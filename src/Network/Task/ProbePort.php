<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\Mark\Mark;
use Phalanx\Network\ProbeResult;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\TaskScope;
use Phalanx\System\TcpClient;
use Phalanx\Task\Scopeable;

final class ProbePort implements Scopeable, Recoverable
{
    public RecoveryPlan $recovery {
        get {
            return $this->recoveryPlan();
        }
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

    private function recoveryPlan(): RecoveryPlan
    {
        return RecoveryPlan::failFast(deadline: Mark::s($this->timeoutSeconds + 0.5));
    }
}
