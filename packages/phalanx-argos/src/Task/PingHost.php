<?php

declare(strict_types=1);

namespace Phalanx\Argos\Task;

use Phalanx\Concurrency\RetryPolicy;
use Phalanx\ExecutionScope;
use Phalanx\Argos\NetworkConfig;
use Phalanx\Argos\ProbeResult;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Retryable;
use React\ChildProcess\Process;
use React\Promise\Deferred;

final class PingHost implements Executable, HasTimeout, Retryable
{
    public float $timeout { get => $this->timeoutSeconds + 1.0; }

    public RetryPolicy $retryPolicy {
        get => $this->retries > 0
            ? RetryPolicy::fixed($this->retries, 500.0)
            : RetryPolicy::fixed(1, 0);
    }

    public function __construct(
        private readonly string $ip,
        private readonly float $timeoutSeconds = 2.0,
        private readonly int $retries = 0,
    ) {}

    public function __invoke(ExecutionScope $scope): ProbeResult
    {
        /** @var NetworkConfig $config */
        $config = $scope->service(NetworkConfig::class);
        $binary = $config->pingBinary;

        $waitSeconds = max(1, (int) ceil($this->timeoutSeconds));
        $cmd = sprintf(
            '%s -c 1 -W %d %s',
            escapeshellarg($binary),
            $waitSeconds,
            escapeshellarg($this->ip),
        );

        $start = hrtime(true);
        $process = new Process($cmd);
        $process->start();

        $deferred = new Deferred();
        $process->on('exit', static function (?int $code) use ($deferred): void {
            $deferred->resolve($code ?? 1);
        });

        try {
            $code = $scope->await($deferred->promise());
        } catch (\Phalanx\Exception\CancelledException $e) {
            if ($process->isRunning()) {
                $process->terminate();
            }
            throw $e;
        }

        $elapsed = (hrtime(true) - $start) / 1e6;

        return new ProbeResult(
            ip: $this->ip,
            reachable: $code === 0,
            latencyMs: $code === 0 ? $elapsed : null,
            method: 'icmp',
        );
    }
}
