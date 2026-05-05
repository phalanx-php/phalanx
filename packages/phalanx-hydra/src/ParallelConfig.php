<?php

declare(strict_types=1);

namespace Phalanx\Hydra;

use Phalanx\Hydra\Dispatch\DispatchStrategy;
use Phalanx\Hydra\Supervisor\SupervisorConfig;
use Phalanx\Hydra\Supervisor\SupervisorStrategy;
use Phalanx\Worker\WorkerDispatch;

final readonly class ParallelConfig
{
    public function __construct(
        public int $agents = 4,
        public int $mailboxLimit = 100,
        public DispatchStrategy $dispatcher = DispatchStrategy::LeastMailbox,
        public SupervisorStrategy $supervision = SupervisorStrategy::RestartOnCrash,
        public ?string $workerScript = null,
        public ?string $autoloadPath = null,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function singleWorker(): self
    {
        return new self(agents: 1);
    }

    public static function cpuBound(): self
    {
        return new self(agents: self::detectCores());
    }

    public function workerDispatch(): WorkerDispatch
    {
        return new ParallelWorkerDispatch($this);
    }

    public function toSupervisorConfig(): SupervisorConfig
    {
        return new SupervisorConfig(
            agents: $this->agents,
            mailboxLimit: $this->mailboxLimit,
            dispatchStrategy: $this->dispatcher,
            supervision: $this->supervision,
        );
    }

    private static function detectCores(): int
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = shell_exec('sysctl -n hw.ncpu');
            if (is_string($output) && is_numeric(trim($output))) {
                return (int) trim($output);
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $output = shell_exec('nproc');
            if (is_string($output) && is_numeric(trim($output))) {
                return (int) trim($output);
            }
        }

        return 4;
    }
}
