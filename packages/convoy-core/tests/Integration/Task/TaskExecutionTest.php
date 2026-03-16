<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Task;

use Convoy\Application;
use Convoy\Concurrency\RetryPolicy;
use Convoy\ExecutionScope;
use Convoy\Scope;
use Convoy\Task\Executable;
use Convoy\Task\HasTimeout;
use Convoy\Task\Pool;
use Convoy\Task\Retryable;
use Convoy\Task\Scopeable;
use Convoy\Task\Task;
use Convoy\Task\Traceable;
use Convoy\Task\UsesPool;
use Convoy\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TaskExecutionTest extends AsyncTestCase
{
    #[Test]
    public function executes_basic_task(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $task = Task::of(static fn() => 42);
        $result = $scope->execute($task);

        $this->assertSame(42, $result);
    }

    #[Test]
    public function executes_invokable_class(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $task = new class implements Scopeable {
            public function __invoke(Scope $scope): string
            {
                return 'invokable result';
            }
        };

        $result = $scope->execute($task);

        $this->assertSame('invokable result', $result);
    }

    #[Test]
    public function applies_retry_from_interface(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $attempts = 0;
            $task = new class($attempts) implements Scopeable, Retryable {
                public RetryPolicy $retryPolicy {
                    get => RetryPolicy::fixed(3, 1);
                }

                public function __construct(private int &$attempts) {}

                public function __invoke(Scope $scope): string
                {
                    $this->attempts++;
                    if ($this->attempts < 3) {
                        throw new \RuntimeException('Not yet');
                    }
                    return 'success';
                }
            };

            $result = $scope->execute($task);

            $this->assertSame('success', $result);
            $this->assertSame(3, $attempts);
        });
    }

    #[Test]
    public function applies_timeout_from_interface(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $task = new class implements Executable, HasTimeout {
                public float $timeout {
                    get => 0.01;
                }

                public function __invoke(ExecutionScope $scope): mixed
                {
                    $scope->delay(1.0);
                    return null;
                }
            };

            $this->expectException(\Convoy\Exception\CancelledException::class);
            $scope->execute($task);
        });
    }

    #[Test]
    public function reads_traceable_name(): void
    {
        $app = Application::starting(['CONVOY_TRACE' => '1'])->compile();
        $scope = $app->createScope();

        $task = new class implements Scopeable, Traceable {
            public string $traceName {
                get => 'MyCustomTask';
            }

            public function __invoke(Scope $scope): string
            {
                return 'traced';
            }
        };

        $scope->execute($task);

        $entries = $scope->trace()->entries();
        $found = array_filter($entries, fn($e) => str_contains((string) $e->subject, 'MyCustomTask'));

        $this->assertNotEmpty($found);
    }

    #[Test]
    public function reads_pool_from_interface(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $task = new class implements Scopeable, UsesPool {
            public \UnitEnum $pool {
                get => Pool::Database;
            }

            public function __invoke(Scope $scope): string
            {
                return 'pooled';
            }
        };

        $result = $scope->execute($task);

        $this->assertSame('pooled', $result);
    }

    #[Test]
    public function task_with_config_applies_name(): void
    {
        $app = Application::starting(['CONVOY_TRACE' => '1'])->compile();
        $scope = $app->createScope();

        $task = Task::of(static fn() => 'named')->withConfig(name: 'ConfiguredTask');

        $scope->execute($task);

        $entries = $scope->trace()->entries();
        $found = array_filter($entries, fn($e) => str_contains((string) $e->subject, 'ConfiguredTask'));

        $this->assertNotEmpty($found);
    }

    #[Test]
    public function execute_fresh_creates_isolated_scope(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $task = Task::of(static fn(ExecutionScope $es) => spl_object_id($es));

        $id1 = $scope->execute($task);
        $id2 = $scope->executeFresh($task);

        $this->assertNotSame($id1, $id2);
    }
}
