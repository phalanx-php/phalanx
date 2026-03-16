<?php

declare(strict_types=1);

namespace Convoy\Parallel;

use Closure;
use Convoy\Parallel\Dispatch\DispatchStrategy;
use Convoy\Parallel\Supervisor\SupervisorConfig;
use Convoy\Parallel\Supervisor\SupervisorStrategy;
use Convoy\Service\LazySingleton;
use Convoy\Service\ServiceGraph;
use Convoy\WorkerDispatch;

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

    /** @return Closure(ServiceGraph, LazySingleton): WorkerDispatch */
    public function workerDispatchFactory(): Closure
    {
        $config = $this;
        return static fn(ServiceGraph $graph, LazySingleton $singletons): WorkerDispatch
            => new ParallelWorkerDispatch($config, $graph, $singletons);
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
}
