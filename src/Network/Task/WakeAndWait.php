<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\Mark\Mark;
use Phalanx\Network\Exception\HostUnreachableException;
use Phalanx\Network\Host;
use Phalanx\Network\ProbeResult;
use Phalanx\Network\ProbeStrategy;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class WakeAndWait implements Executable, Recoverable
{
    public RecoveryPlan $recovery {
        get {
            return $this->recoveryPlan();
        }
    }

    public function __construct(
        private readonly string $mac,
        private readonly string $ip,
        private readonly ProbeStrategy $readyCheck,
        private readonly int $maxRetries = 30,
        private readonly float $retryIntervalSeconds = 2.0,
    ) {
    }

    public function __invoke(ExecutionScope $scope): Host
    {
        $scope->execute(new WakeHost($this->mac));

        $probe = $this->readyCheck->forHost($this->ip);
        $result = $scope->execute($probe);

        if ($result instanceof ProbeResult && !$result->reachable) {
            throw new HostUnreachableException($this->ip, $result->method ?? 'unknown');
        }

        return new Host(ip: $this->ip, mac: $this->mac);
    }

    private function recoveryPlan(): RecoveryPlan
    {
        return RecoveryPlan::polling(
            interval: Mark::s($this->retryIntervalSeconds),
            deadline: Mark::s($this->maxRetries * $this->retryIntervalSeconds + 10.0),
        );
    }
}
