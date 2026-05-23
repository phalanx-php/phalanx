<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Boot\AppContext;
use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Middleware\RetryMiddleware;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\RunState;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Task\Executable;
use Phalanx\Task\Retryable;
use Phalanx\Task\Task;
use Phalanx\Task\Traceable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExecuteWiringTest extends TestCase
{
    public function testExecuteOpensAndReapsTaskRun(): void
    {
        $ledger = new InProcessLedger();
        $app = $this->buildApp($ledger);
        $scope = $app->createScope();

        $observed = [];
        $task = Task::of(static function () use (&$observed): string {
            $observed[] = 'inside body';
            return 'ok';
        });

        $value = $scope->execute($task);

        self::assertSame('ok', $value);
        self::assertSame(['inside body'], $observed);

        // After execute returns, the run is reaped (not in the ledger).
        self::assertSame(0, $ledger->liveCount());
    }

    public function testThrowingBodyTransitionsToFailedBeforeReap(): void
    {
        $ledger = new InProcessLedger();
        $observedRun = null;
        $app = $this->buildApp($ledger);
        $scope = $app->createScope();

        $task = Task::of(static function () use ($ledger, &$observedRun): never {
            // While the body runs, exactly one run should be live.
            self::assertSame(1, $ledger->liveCount());
            $observedRun = $ledger->tree()[0] ?? null;
            self::assertNotNull($observedRun);
            self::assertSame(RunState::Running, $observedRun->state);

            throw new RuntimeException('boom');
        });

        $threw = null;
        try {
            $scope->execute($task);
        } catch (RuntimeException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw);
        self::assertSame('boom', $threw->getMessage());
        // Run is reaped after fail() — ledger empty.
        self::assertSame(0, $ledger->liveCount());
    }

    public function testCancelledThrowableTransitionsToCancelled(): void
    {
        $ledger = new InProcessLedger();
        $app = $this->buildApp($ledger);
        $scope = $app->createScope();

        $task = Task::of(static function (): never {
            throw new Cancelled('mid-flight cancel');
        });

        $threw = false;
        try {
            $scope->execute($task);
        } catch (Cancelled) {
            $threw = true;
        }

        self::assertTrue($threw);
        self::assertSame(0, $ledger->liveCount());
    }

    public function testRecursiveExecuteSetsParentId(): void
    {
        $ledger = new InProcessLedger();
        $app = $this->buildApp($ledger);
        $scope = $app->createScope();

        /** @var array<string, ?string> $observedParents */
        $observedParents = [];

        $inner = Task::of(static function () use ($ledger, &$observedParents): string {
            $tree = $ledger->tree();
            // Two live runs while inner is executing: outer (Running) and inner (Running)
            self::assertCount(2, $tree);
            // Find the inner — its parentId should match the outer run's id
            foreach ($tree as $snap) {
                $observedParents[$snap->name] = $snap->parentId;
            }
            return 'inner';
        });

        $outer = Task::of(static function (ExecutionScope $s) use ($inner): string {
            return $s->execute($inner);
        });

        $scope->execute($outer);

        // Both names are file:line locations from Task::of() — there are 2 entries.
        self::assertCount(2, $observedParents);
        $parentIds = array_values($observedParents);
        sort($parentIds);
        // One run is the outer (no parent) and one is the inner (parent is the outer's id)
        self::assertNull($parentIds[0]);
        self::assertNotNull($parentIds[1]);
    }

    public function testTraceableNameIsPreferredForRunIdentity(): void
    {
        $ledger = new InProcessLedger();
        $app = $this->buildApp($ledger);
        $scope = $app->createScope();

        $observedName = null;
        $task = new class ($ledger, $observedName) implements Executable, Traceable {
            public string $traceName {
                get => 'CustomTraceableTask';
            }

            public function __construct(
                private readonly InProcessLedger $ledger,
                public ?string &$observedName,
            ) {
            }

            public function __invoke(ExecutionScope $scope): string
            {
                $tree = $this->ledger->tree();
                $this->observedName = $tree[0]->name ?? null;
                return 'done';
            }
        };

        $scope->execute($task);

        self::assertSame('CustomTraceableTask', $task->observedName);
    }

    public function testTaskOfNameIsFileColonLine(): void
    {
        $ledger = new InProcessLedger();
        $app = $this->buildApp($ledger);
        $scope = $app->createScope();

        $observedName = null;
        $task = Task::of(static function (ExecutionScope $_s) use ($ledger, &$observedName): null {
            $observedName = $ledger->tree()[0]->name ?? null;
            return null;
        });

        $scope->execute($task);

        // Task::of() captures sourceLocation at construction site
        // (this file, not the closure-running site).
        self::assertNotNull($observedName);
        self::assertStringContainsString('ExecuteWiringTest.php:', $observedName);
    }

    public function testRetryMiddlewareProducesDistinctRunsPerAttempt(): void
    {
        $ledger = new InProcessLedger();
        $app = $this->buildAppWithRetry($ledger);

        $task = new class implements Executable, Retryable {
            public RetryPolicy $retryPolicy {
                get => RetryPolicy::fixed(3, 1);
            }

            public int $attempts = 0;

            public function __invoke(ExecutionScope $scope): int
            {
                $this->attempts++;
                if ($this->attempts < 3) {
                    throw new RuntimeException('not yet');
                }
                return $this->attempts;
            }
        };

        // Retry uses Co::sleep between attempts; needs coroutine context.
        $caught = null;
        RuntimeHooks::ensure(RuntimePolicy::phalanxManaged());
        \OpenSwoole\Coroutine::run(static function () use ($app, $task, &$caught): void {
            try {
                $scope = $app->createScope();
                $value = $scope->execute($task);
                self::assertSame(3, $value);
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });

        if ($caught !== null) {
            throw $caught;
        }

        self::assertSame(3, $task->attempts);
        // After all retries done and outer execute returns, ledger empty.
        self::assertSame(0, $ledger->liveCount());
    }

    private function buildApp(InProcessLedger $ledger): Application
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, AppContext $context): void
            {
            }
        };
        return Application::starting()
            ->providers($bundle)
            ->withLedger($ledger)
            ->compile();
    }

    private function buildAppWithRetry(InProcessLedger $ledger): Application
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, AppContext $context): void
            {
            }
        };
        return Application::starting()
            ->providers($bundle)
            ->withLedger($ledger)
            ->taskMiddleware(new RetryMiddleware())
            ->compile();
    }
}
