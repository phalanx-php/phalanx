<?php

declare(strict_types=1);

namespace Phalanx\Network\Task;

use Phalanx\Concurrency\RetryPolicy;
use Phalanx\ExecutionScope;
use Phalanx\Network\Exception\HostUnreachableException;
use Phalanx\Network\Host;
use Phalanx\Network\ProbeResult;
use Phalanx\Network\ProbeStrategy;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;

final class WakeAndWait implements Executable, HasTimeout
{
    public float $timeout {
        get => $this->maxRetries * $this->retryIntervalSeconds + 10.0;
    }

    public function __construct(
        private readonly string $mac,
        private readonly string $ip,
        private readonly ProbeStrategy $readyCheck,
        private readonly int $maxRetries = 30,
        private readonly float $retryIntervalSeconds = 2.0,
    ) {}

    public function __invoke(ExecutionScope $scope): Host
    {
        $scope->execute(new WakeHost($this->mac));

        $probe = $this->readyCheck->forHost($this->ip);
        $policy = RetryPolicy::fixed(
            $this->maxRetries,
            $this->retryIntervalSeconds * 1000,
        );

        $result = $scope->retry($probe, $policy);

        if ($result instanceof ProbeResult && !$result->reachable) {
            throw new HostUnreachableException($this->ip, $result->method ?? 'unknown');
        }

        return new Host(ip: $this->ip, mac: $this->mac);
    }
}
