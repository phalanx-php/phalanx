<?php

declare(strict_types=1);

namespace Phalanx\Hydra;

use Phalanx\Boot\AppContext;
use Phalanx\Hydra\Dispatch\DispatchStrategy;
use Phalanx\Hydra\Supervisor\SupervisorConfig;
use Phalanx\Hydra\Supervisor\SupervisorStrategy;
use Phalanx\Worker\WorkerDispatch;

final readonly class ParallelConfig
{
    public const string CONTEXT_AGENTS = 'HYDRA_AGENTS';
    public const string CONTEXT_MAILBOX_LIMIT = 'HYDRA_MAILBOX_LIMIT';
    public const string CONTEXT_DISPATCHER = 'HYDRA_DISPATCHER';
    public const string CONTEXT_SUPERVISION = 'HYDRA_SUPERVISION';
    public const string CONTEXT_WORKER_SCRIPT = 'HYDRA_WORKER_SCRIPT';
    public const string CONTEXT_AUTOLOAD_PATH = 'HYDRA_AUTOLOAD_PATH';

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

    public static function fromContext(AppContext $context): self
    {
        return new self(
            agents: $context->int(self::CONTEXT_AGENTS, self::default()->agents),
            mailboxLimit: $context->int(self::CONTEXT_MAILBOX_LIMIT, self::default()->mailboxLimit),
            dispatcher: self::dispatchStrategy($context->string(
                self::CONTEXT_DISPATCHER,
                self::default()->dispatcher->name,
            )),
            supervision: self::supervisorStrategy($context->string(
                self::CONTEXT_SUPERVISION,
                self::default()->supervision->name,
            )),
            workerScript: $context->has(self::CONTEXT_WORKER_SCRIPT)
                ? $context->string(self::CONTEXT_WORKER_SCRIPT)
                : null,
            autoloadPath: $context->has(self::CONTEXT_AUTOLOAD_PATH)
                ? $context->string(self::CONTEXT_AUTOLOAD_PATH)
                : null,
        );
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

    private static function dispatchStrategy(string $value): DispatchStrategy
    {
        return match (self::normalized($value)) {
            'roundrobin' => DispatchStrategy::RoundRobin,
            'leastmailbox' => DispatchStrategy::LeastMailbox,
            default => DispatchStrategy::LeastMailbox,
        };
    }

    private static function supervisorStrategy(string $value): SupervisorStrategy
    {
        return match (self::normalized($value)) {
            'ignore' => SupervisorStrategy::Ignore,
            'stopall' => SupervisorStrategy::StopAll,
            'restartoncrash' => SupervisorStrategy::RestartOnCrash,
            default => SupervisorStrategy::RestartOnCrash,
        };
    }

    private static function normalized(string $value): string
    {
        return strtolower(str_replace(['-', '_', ' '], '', $value));
    }
}
