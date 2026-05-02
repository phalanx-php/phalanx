<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kernel\Cases;

use OpenSwoole\Coroutine;
use Phalanx\Benchmarks\Kernel\AbstractBenchmarkCase;
use Phalanx\Benchmarks\Kernel\BenchmarkContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TransactionScope;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\SwooleTableLedger;
use Phalanx\Supervisor\TransactionLease;
use Phalanx\Task\Task;
use Phalanx\Trace\Trace;

final class ScopeCreateDisposeCase extends AbstractBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('scope_create_dispose', 10_000, 100);
    }

    public function run(BenchmarkContext $context): void
    {
        $scope = $context->scope();
        $scope->dispose();
    }
}

final class ExecuteNoopTaskCase extends AbstractBenchmarkCase
{
    private ?ExecutionScope $scope = null;

    private ?Task $task = null;

    public function __construct()
    {
        parent::__construct('execute_noop_task', 10_000, 100);
    }

    public function run(BenchmarkContext $context): void
    {
        $this->scope ??= $context->scope();
        $this->task ??= Task::named('bench.noop', static fn(ExecutionScope $scope): null => null);

        $this->scope->execute($this->task);
    }
}

final class ExecuteStaticTaskOfCase extends AbstractBenchmarkCase
{
    private ?ExecutionScope $scope = null;

    public function __construct()
    {
        parent::__construct('execute_static_task_of', 10_000, 100);
    }

    public function run(BenchmarkContext $context): void
    {
        $this->scope ??= $context->scope();

        $this->scope->execute(Task::of(static fn(ExecutionScope $scope): null => null));
    }
}

final class SupervisorLifecycleCase extends AbstractBenchmarkCase
{
    private ?ExecutionScope $scope = null;

    private ?Supervisor $supervisor = null;

    private ?Task $task = null;

    public function __construct()
    {
        parent::__construct('supervisor_lifecycle', 50_000, 500);
    }

    public function run(BenchmarkContext $context): void
    {
        if ($this->scope === null || $this->supervisor === null || $this->task === null) {
            $app = $context->app();
            $this->scope = $app->createScope();
            $this->supervisor = $app->supervisor();
            $this->task = Task::named('bench.supervisor', static fn(ExecutionScope $scope): null => null);
        }

        $run = $this->supervisor->start($this->task, $this->scope, DispatchMode::Inline);
        $this->supervisor->markRunning($run);
        $this->supervisor->complete($run, null);
        $this->supervisor->reap($run);
    }
}

final class ConcurrentNoopCase extends AbstractBenchmarkCase
{
    /** @var array<int, Task> */
    private array $tasks = [];

    private ?ExecutionScope $scope = null;

    public function __construct(private readonly int $count)
    {
        parent::__construct("concurrent_noop_{$count}", max(50, intdiv(10_000, $count)), 10);
    }

    public function run(BenchmarkContext $context): void
    {
        $this->scope ??= $context->scope();

        if ($this->tasks === []) {
            for ($i = 0; $i < $this->count; $i++) {
                $this->tasks[] = Task::named("bench.concurrent.noop.{$i}", static fn(ExecutionScope $scope): int => 1);
            }
        }

        $this->scope->concurrent($this->tasks);
    }
}

final class ConcurrentDelayCase extends AbstractBenchmarkCase
{
    /** @var array<int, Task> */
    private array $tasks = [];

    private ?ExecutionScope $scope = null;

    public function __construct(private readonly int $count)
    {
        parent::__construct("concurrent_delay_{$count}", 100, 5);
    }

    public function run(BenchmarkContext $context): void
    {
        $this->scope ??= $context->scope();

        if ($this->tasks === []) {
            for ($i = 0; $i < $this->count; $i++) {
                $this->tasks[] = Task::named(
                    "bench.concurrent.delay.{$i}",
                    static function (ExecutionScope $scope): int {
                        $scope->delay(0.001);
                        return 1;
                    },
                );
            }
        }

        $this->scope->concurrent($this->tasks);
    }
}

final class SingleflightWaitersCase extends AbstractBenchmarkCase
{
    private ?ExecutionScope $scope = null;

    public function __construct(private readonly int $waiters)
    {
        parent::__construct("singleflight_waiters_{$waiters}", 100, 5);
    }

    public function run(BenchmarkContext $context): void
    {
        $this->scope ??= $context->scope();

        $tasks = [
            Task::named(
                'bench.singleflight.owner',
                static fn(ExecutionScope $scope): mixed => $scope->singleflight(
                    'bench-key',
                    Task::named('bench.singleflight.work', static function (ExecutionScope $owner): string {
                        $owner->delay(0.001);
                        return 'ok';
                    }),
                ),
            ),
        ];

        for ($i = 0; $i < $this->waiters; $i++) {
            $tasks[] = Task::named(
                "bench.singleflight.waiter.{$i}",
                static fn(ExecutionScope $scope): mixed => $scope->singleflight(
                    'bench-key',
                    Task::named('bench.singleflight.unused', static fn(ExecutionScope $scope): string => 'unused'),
                ),
            );
        }

        $this->scope->concurrent($tasks);
    }
}

final class CancelSleepingChildrenCase extends AbstractBenchmarkCase
{
    public function __construct(private readonly int $count)
    {
        parent::__construct("cancel_sleeping_children_{$count}", 100, 5);
    }

    public function run(BenchmarkContext $context): void
    {
        $scope = $context->scope();
        $tasks = [];

        for ($i = 0; $i < $this->count; $i++) {
            $tasks[] = Task::named(
                "bench.cancel.sleep.{$i}",
                static function (ExecutionScope $child): void {
                    $child->delay(1.0);
                },
            );
        }

        Coroutine::create(static function () use ($scope): void {
            Coroutine::usleep(1_000);
            $scope->cancellation()->cancel();
        });

        try {
            $scope->concurrent($tasks);
        } catch (\Throwable) {
            // Cancellation is the measured path for this benchmark.
        } finally {
            $scope->dispose();
        }
    }
}

final class InProcessLedgerLifecycleCase extends AbstractBenchmarkCase
{
    private ?ExecutionScope $scope = null;

    private ?Supervisor $supervisor = null;

    private ?Task $task = null;

    public function __construct()
    {
        parent::__construct('ledger_inprocess_lifecycle', 50_000, 500);
    }

    public function run(BenchmarkContext $context): void
    {
        if ($this->scope === null || $this->supervisor === null || $this->task === null) {
            $app = $context->app(new InProcessLedger());
            $this->scope = $app->createScope();
            $this->supervisor = $app->supervisor();
            $this->task = Task::named('bench.ledger.inprocess', static fn(ExecutionScope $scope): null => null);
        }

        $run = $this->supervisor->start($this->task, $this->scope, DispatchMode::Inline);
        $this->supervisor->markRunning($run);
        $this->supervisor->complete($run, null);
        $this->supervisor->reap($run);
    }
}

final class SwooleTableLedgerLifecycleCase extends AbstractBenchmarkCase
{
    private ?ExecutionScope $scope = null;

    private ?Supervisor $supervisor = null;

    private ?Task $task = null;

    public function __construct()
    {
        parent::__construct('ledger_swoole_table_lifecycle', 10_000, 100);
    }

    public function run(BenchmarkContext $context): void
    {
        if ($this->scope === null || $this->supervisor === null || $this->task === null) {
            $app = $context->app(new SwooleTableLedger(1024));
            $this->scope = $app->createScope();
            $this->supervisor = $app->supervisor();
            $this->task = Task::named('bench.ledger.swoole_table', static fn(ExecutionScope $scope): null => null);
        }

        $run = $this->supervisor->start($this->task, $this->scope, DispatchMode::Inline);
        $this->supervisor->markRunning($run);
        $this->supervisor->complete($run, null);
        $this->supervisor->reap($run);
    }
}

final class TransactionScopeEnterExitCase extends AbstractBenchmarkCase
{
    private ?ExecutionScope $scope = null;

    private ?Task $task = null;

    public function __construct()
    {
        parent::__construct('transaction_scope_enter_exit', 5_000, 50);
    }

    public function run(BenchmarkContext $context): void
    {
        $this->scope ??= $context->scope();
        $this->task ??= Task::named(
            'bench.transaction',
            static fn(ExecutionScope $scope): mixed => $scope->transaction(
                TransactionLease::open('bench/postgres', 'tx'),
                static fn(TransactionScope $transaction): null => null,
            ),
        );

        $this->scope->execute($this->task);
    }
}

/** @return list<\Phalanx\Benchmarks\Kernel\BenchmarkCase> */
function aegisKernelCases(): array
{
    return [
        new ScopeCreateDisposeCase(),
        new ExecuteNoopTaskCase(),
        new ExecuteStaticTaskOfCase(),
        new SupervisorLifecycleCase(),
        new ConcurrentNoopCase(100),
        new ConcurrentDelayCase(100),
        new SingleflightWaitersCase(100),
        new CancelSleepingChildrenCase(100),
        new InProcessLedgerLifecycleCase(),
        new SwooleTableLedgerLifecycleCase(),
        new TransactionScopeEnterExitCase(),
    ];
}
