<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Integration;

use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Hydra\ParallelConfig;
use Phalanx\Hydra\Tests\Fixtures\GreetThroughWorkerService;
use Phalanx\Hydra\Tests\Fixtures\NeedsExecutionScope;
use Phalanx\Hydra\Tests\Fixtures\SlowWorkerTask;
use Phalanx\Hydra\Tests\Fixtures\StatefulCounterTask;
use Phalanx\Hydra\Tests\Fixtures\WorkerGreetingService;
use Phalanx\Hydra\Tests\Fixtures\WorkerGreetingServiceImpl;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\AsyncTestCase;
use Phalanx\Tests\Support\Fixtures\AddNumbers;
use Phalanx\Tests\Support\Fixtures\CpuIntensiveTask;
use Phalanx\Tests\Support\Fixtures\TaskThatThrows;
use Phalanx\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class InWorkerTest extends AsyncTestCase
{
    private Application $app;

    #[Test]
    public function executesSimpleTaskInWorker(): void
    {
        $app = $this->app;

        $this->runAsync(static function () use ($app): void {
            $scope = $app->createScope();

            try {
                $result = $scope->inWorker(new AddNumbers(2, 3));

                self::assertSame(5, $result);
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function executesCpuIntensiveTask(): void
    {
        $app = $this->app;

        $this->runAsync(static function () use ($app): void {
            $scope = $app->createScope();

            try {
                $result = $scope->inWorker(new CpuIntensiveTask(100));

                self::assertSame(4950, $result);
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function propagatesExceptionsFromWorker(): void
    {
        $app = $this->app;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Intentional failure');

        $this->runAsync(static function () use ($app): void {
            $scope = $app->createScope();

            try {
                $scope->inWorker(new TaskThatThrows('Intentional failure'));
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function proxiesParentServicesFromWorker(): void
    {
        $app = $this->app;

        $this->runAsync(static function () use ($app): void {
            $scope = $app->createScope();

            try {
                $result = $scope->inWorker(new GreetThroughWorkerService('hydra'));

                self::assertSame('hello hydra', $result);
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function cancelledWorkerDispatchRestartsCleanlyForNextTask(): void
    {
        $app = $this->buildApp(new ParallelConfig(agents: 1));

        try {
            $this->runAsync(static function () use ($app): void {
                $scope = $app->createScope();

                try {
                    try {
                        $scope->timeout(
                            0.05,
                            Task::of(static fn(ExecutionScope $s): mixed => $s->inWorker(new SlowWorkerTask(250_000))),
                        );
                        self::fail('Expected worker timeout to cancel the in-flight dispatch.');
                    } catch (Cancelled $e) {
                        self::assertSame('timeout after 0.05s', $e->getMessage());
                    }
                } finally {
                    $scope->dispose();
                }
            });

            $this->runAsync(static function () use ($app): void {
                $nextScope = $app->createScope();

                try {
                    self::assertSame(5, $nextScope->inWorker(new AddNumbers(2, 3)));
                } finally {
                    $nextScope->dispose();
                }
            });
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function cancelledWorkerLockWaitDoesNotDispatchAfterCancellation(): void
    {
        $app = $this->buildApp(new ParallelConfig(agents: 1));

        try {
            $this->runAsync(static function () use ($app): void {
                $scope = $app->createScope();

                try {
                    $settled = $scope->settle(...[
                        'busy' => Task::of(
                            static fn(ExecutionScope $s): mixed => $s->inWorker(new SlowWorkerTask(250_000)),
                        ),
                        'waiter' => Task::of(
                            static fn(ExecutionScope $s): mixed => $s->timeout(
                                0.05,
                                Task::of(
                                    static fn(ExecutionScope $t): mixed => $t->inWorker(new AddNumbers(2, 3)),
                                ),
                            ),
                        ),
                    ]);

                    $waiterError = $settled->errors['waiter'] ?? null;

                    self::assertSame('slow-done', $settled->get('busy'));
                    self::assertInstanceOf(Cancelled::class, $waiterError);
                    self::assertStringContainsString(
                        'timeout after 0.05s',
                        $waiterError->getMessage(),
                    );

                    self::assertSame(5, $scope->inWorker(new AddNumbers(2, 3)));
                } finally {
                    $scope->dispose();
                }
            });
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function workerProcessRestartsAfterCallerScopeDisposal(): void
    {
        $app = $this->buildApp(new ParallelConfig(agents: 1));

        try {
            $this->runAsync(static function () use ($app): void {
                $scope = $app->createScope();

                try {
                    self::assertSame(1, $scope->inWorker(new StatefulCounterTask()));
                } finally {
                    $scope->dispose();
                }
            });

            $this->runAsync(static function () use ($app): void {
                $scope = $app->createScope();

                try {
                    self::assertSame(1, $scope->inWorker(new StatefulCounterTask()));
                } finally {
                    $scope->dispose();
                }
            });
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function multipleTasksExecuteSequentially(): void
    {
        $app = $this->app;

        $this->runAsync(static function () use ($app): void {
            $scope = $app->createScope();

            try {
                $result1 = $scope->inWorker(new AddNumbers(1, 2));
                $result2 = $scope->inWorker(new AddNumbers(3, 4));
                $result3 = $scope->inWorker(new AddNumbers(5, 6));

                self::assertSame(3, $result1);
                self::assertSame(7, $result2);
                self::assertSame(11, $result3);
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function parallelTasksExecuteConcurrently(): void
    {
        $app = $this->app;

        $this->runAsync(static function () use ($app): void {
            $scope = $app->createScope();

            try {
                $results = $scope->execute(Task::of(
                    static fn(ExecutionScope $es): array => $es->concurrent(...[
                        'a' => Task::of(static fn(ExecutionScope $s): mixed => $s->inWorker(new AddNumbers(1, 2))),
                        'b' => Task::of(static fn(ExecutionScope $s): mixed => $s->inWorker(new AddNumbers(3, 4))),
                    ])
                ));

                self::assertSame(3, $results['a']);
                self::assertSame(7, $results['b']);
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function rejectsTasksThatRequireExecutionScope(): void
    {
        $app = $this->app;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('current worker runtime exposes');

        $this->runAsync(static function () use ($app): void {
            $scope = $app->createScope();

            try {
                $scope->inWorker(new NeedsExecutionScope());
            } finally {
                $scope->dispose();
            }
        });
    }

    protected function setUp(): void
    {
        $this->app = $this->buildApp(new ParallelConfig(agents: 2));
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    private function buildApp(ParallelConfig $config): Application
    {
        $bundle = TestServiceBundle::create()
            ->singleton(
                WorkerGreetingService::class,
                static fn(): WorkerGreetingService => new WorkerGreetingServiceImpl(),
            );

        $app = Application::starting()
            ->providers($bundle)
            ->withWorkerDispatch($config->workerDispatch())
            ->compile();

        $app->startup();

        return $app;
    }
}
