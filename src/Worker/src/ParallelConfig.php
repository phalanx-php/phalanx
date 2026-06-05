<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Config\Config;
use Phalanx\Config\Env;
use Phalanx\Config\Issue;
use Phalanx\Config\ValidationContext;
use Phalanx\Worker\Dispatch\DispatchStrategy;
use Phalanx\Worker\Supervisor\SupervisorConfig;
use Phalanx\Worker\Supervisor\SupervisorStrategy;
use Phalanx\Worker\WorkerDispatch;

final class ParallelConfig implements Config
{
    public const string CONTEXT_AGENTS = 'WORKER_AGENTS';
    public const string CONTEXT_MAILBOX_LIMIT = 'WORKER_MAILBOX_LIMIT';
    public const string CONTEXT_DISPATCHER = 'WORKER_DISPATCHER';
    public const string CONTEXT_SUPERVISION = 'WORKER_SUPERVISION';
    public const string CONTEXT_WORKER_SCRIPT = 'WORKER_SCRIPT';
    public const string CONTEXT_AUTOLOAD_PATH = 'WORKER_AUTOLOAD_PATH';

    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env(key: self::CONTEXT_AGENTS, description: 'Number of worker child processes')]
        public int $agents = 4,
        #[Env(key: self::CONTEXT_MAILBOX_LIMIT, description: 'Maximum queued tasks per worker')]
        public int $mailboxLimit = 100,
        #[Env(key: self::CONTEXT_DISPATCHER, description: 'Worker dispatch strategy')]
        public DispatchStrategy $dispatcher = DispatchStrategy::LeastMailbox,
        #[Env(key: self::CONTEXT_SUPERVISION, description: 'Worker supervision strategy')]
        public SupervisorStrategy $supervision = SupervisorStrategy::RestartOnCrash,
        #[Env(key: self::CONTEXT_WORKER_SCRIPT, description: 'Worker process bootstrap script')]
        public ?string $workerScript = null,
        #[Env(key: self::CONTEXT_AUTOLOAD_PATH, description: 'Worker process Composer autoload path')]
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
        return new ParallelDispatch($this);
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

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        $issues = [];

        if ($this->agents < 1) {
            $issues[] = Issue::error(self::CONTEXT_AGENTS, 'Must be >= 1');
        }

        if ($this->mailboxLimit < 1) {
            $issues[] = Issue::error(self::CONTEXT_MAILBOX_LIMIT, 'Must be >= 1');
        }

        return $issues;
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
