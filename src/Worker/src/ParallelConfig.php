<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Boot\AppContext;
use Phalanx\Worker\Dispatch\DispatchStrategy;
use Phalanx\Worker\Supervisor\SupervisorConfig;
use Phalanx\Worker\Supervisor\SupervisorStrategy;
use Phalanx\Worker\WorkerDispatch;

final readonly class ParallelConfig
{
    public const string CONTEXT_AGENTS = 'WORKER_AGENTS';
    public const string CONTEXT_MAILBOX_LIMIT = 'WORKER_MAILBOX_LIMIT';
    public const string CONTEXT_DISPATCHER = 'WORKER_DISPATCHER';
    public const string CONTEXT_SUPERVISION = 'WORKER_SUPERVISION';
    public const string CONTEXT_WORKER_SCRIPT = 'WORKER_SCRIPT';
    public const string CONTEXT_AUTOLOAD_PATH = 'WORKER_AUTOLOAD_PATH';

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
