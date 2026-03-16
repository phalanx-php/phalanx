<?php

declare(strict_types=1);

namespace Convoy\Parallel\Supervisor;

use Convoy\Parallel\Dispatch\DispatchStrategy;

final readonly class SupervisorConfig
{
    public function __construct(
        public int $agents = 4,
        public int $mailboxLimit = 100,
        public DispatchStrategy $dispatchStrategy = DispatchStrategy::LeastMailbox,
        public SupervisorStrategy $supervision = SupervisorStrategy::RestartOnCrash,
        public int $maxRestarts = 5,
        public float $restartWindow = 60.0,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function singleAgent(): self
    {
        return new self(agents: 1);
    }

    public static function cpuBound(): self
    {
        $cores = self::detectCores();
        return new self(agents: $cores);
    }

    private static function detectCores(): int
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = shell_exec('sysctl -n hw.ncpu');
            if ($output !== null && is_numeric(trim($output))) {
                return (int) trim($output);
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $output = shell_exec('nproc');
            if ($output !== null && is_numeric(trim($output))) {
                return (int) trim($output);
            }
        }

        return 4;
    }
}
