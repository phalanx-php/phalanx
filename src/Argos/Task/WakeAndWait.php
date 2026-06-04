<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use Phalanx\Argos\Exception\HostUnreachableException;
use Phalanx\Argos\Host;
use Phalanx\Argos\ProbeResult;
use Phalanx\Argos\ProbeStrategy;
use Phalanx\Mark\Mark;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class WakeAndWait implements Executable, Recoverable
{
    public RecoveryPlan $recovery {
        get => RecoveryPlan::polling(
            interval: Mark::s($this->retryIntervalSeconds),
            deadline: Mark::s($this->maxRetries * $this->retryIntervalSeconds + 10.0),
        );
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
}
