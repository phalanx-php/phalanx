<?php

declare(strict_types=1);

namespace Phalanx\Worker;

final readonly class ParallelConfig
{
    public function __construct(
        public int $agents = 2,
        public int $mailboxLimit = 64,
        public DispatchStrategy $strategy = DispatchStrategy::RoundRobin,
        public string $workerScript = '',
        public string $autoloadPath = '',
        public float $gracefulShutdownTimeout = 2.0,
    ) {
    }

    public static function cpuBound(): self
    {
        $cpus = (int) (shell_exec('sysctl -n hw.logicalcpu 2>/dev/null') ?: '4');
        return new self(agents: max(1, $cpus));
    }
}
