<?php

declare(strict_types=1);

namespace Phalanx\Argos;

use Phalanx\Argos\Task\PingHost;
use Phalanx\Argos\Task\ProbePort;
use Phalanx\Argos\Task\ProbeUdp;
use Phalanx\Task\Executable;

final readonly class ProbeStrategy
{
    /** @param array<string, mixed> $baseParams */
    private function __construct(
        private string $taskClass,
        private array $baseParams,
    ) {}

    public static function udp(int $port, string $payload, float $timeout = 2.0): self
    {
        return new self(ProbeUdp::class, [
            'port' => $port,
            'payload' => $payload,
            'timeoutSeconds' => $timeout,
        ]);
    }

    public static function tcp(int $port, float $timeout = 2.0): self
    {
        return new self(ProbePort::class, [
            'port' => $port,
            'timeoutSeconds' => $timeout,
        ]);
    }

    public static function ping(float $timeout = 2.0, int $retries = 0): self
    {
        return new self(PingHost::class, [
            'timeoutSeconds' => $timeout,
            'retries' => $retries,
        ]);
    }

    public function forHost(string $ip): Executable
    {
        /** @var Executable $task */
        $task = new ($this->taskClass)($ip, ...$this->baseParams);
        return $task;
    }

    public function taskClass(): string
    {
        return $this->taskClass;
    }
}
