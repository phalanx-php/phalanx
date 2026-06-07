<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\Mark\Mark;
use Phalanx\Network\NetworkConfig;
use Phalanx\Network\ProbeResult;
use Phalanx\Recovery\Backoff;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\TaskScope;
use Phalanx\System\SystemCommand;
use Phalanx\Task\Scopeable;

final class PingHost implements Scopeable, Recoverable
{
    public RecoveryPlan $recovery {
        get => $this->recoveryPlan();
    }

    public function __construct(
        private readonly string $ip,
        private readonly float $timeoutSeconds = 2.0,
        private readonly int $retries = 0,
    ) {
    }

    public function __invoke(TaskScope $scope): ProbeResult
    {
        $config = $scope->service(NetworkConfig::class);

        $waitSeconds = max(1, (int) ceil($this->timeoutSeconds));
        $command = SystemCommand::from(
            $config->pingBinary,
            '-c',
            '1',
            '-W',
            (string) $waitSeconds,
            $this->ip,
        );

        $start = Mark::now();
        $result = $command($scope);
        $elapsed = $start->elapsed();

        return new ProbeResult(
            ip: $this->ip,
            reachable: $result->successful,
            latencyMs: $result->successful ? $elapsed->toMilliseconds() : null,
            method: 'icmp',
        );
    }

    private function recoveryPlan(): RecoveryPlan
    {
        return RecoveryPlan::defaultRetry(
            attempts: $this->retries > 0 ? $this->retries : 1,
            attemptTimeout: Mark::s($this->timeoutSeconds + 1.0),
            backoff: Backoff::fixed(Mark::ms(500)),
        );
    }
}
