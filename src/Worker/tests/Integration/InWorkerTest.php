<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Integration;

use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Worker\Bundle;
use Phalanx\Worker\Worker;
use Phalanx\Mark\Mark;
use Phalanx\Worker\ParallelConfig;
use Phalanx\Worker\Tests\Fixtures\GreetThroughServiceTask;
use Phalanx\Worker\Tests\Fixtures\NeedsExecutionScope;
use Phalanx\Worker\Tests\Fixtures\SlowTask;
use Phalanx\Worker\Tests\Fixtures\StatefulCounterTask;
use Phalanx\Worker\Tests\Fixtures\GreetingService;
use Phalanx\Worker\Tests\Fixtures\GreetingServiceImpl;
use Phalanx\Worker\Tests\Fixtures\StderrTask;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestApp;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Runtime\Tests\Support\Fixtures\AddNumbers;
use Phalanx\Runtime\Tests\Support\Fixtures\CpuIntensiveTask;
use Phalanx\Runtime\Tests\Support\Fixtures\TaskThatThrows;
use Phalanx\Runtime\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;

final class InWorkerTest extends PhalanxTestCase
{
    private Application $app;

    #[Test]
    public function executesSimpleTaskInWorker(): void
    {
        $this->buildTestApp(new ParallelConfig(agents: 2))
            ->worker
            ->run(new AddNumbers(2, 3))
            ->assertValueSame(5)
            ->assertNoLiveRuntimeScopes()
            ->assertNoLiveTasks();
    }

    #[Test]
    public function executesCpuIntensiveTask(): void
    {
        $this->buildTestApp(new ParallelConfig(agents: 2))
            ->worker
            ->run(new CpuIntensiveTask(100))
            ->assertValueSame(4950)
            ->assertNoLiveRuntimeScopes()
            ->assertNoLiveTasks();
    }

    #[Test]
    public function propagatesExceptionsFromWorker(): void
    {
        $app = $this->buildTestApp(new ParallelConfig(agents: 2));

        try {
            $app->worker->run(new TaskThatThrows('Intentional failure'));

            self::fail('Expected worker task exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('Intentional failure', $e->getMessage());
        }

        $app->runtime
            ->assertNoLiveResources(RuntimeResourceSid::Scope)
            ->assertNoLiveTasks();
    }

    #[Test]
    public function proxiesParentServicesFromWorker(): void
    {
        $this->buildTestApp(new ParallelConfig(agents: 2))
            ->worker
            ->run(new GreetThroughServiceTask('worker'))
            ->assertValueSame('hello worker')
            ->assertNoLiveRuntimeScopes()
            ->assertNoLiveTasks();
    }

    #[Test]
    public function drainsWorkerStderrWithoutPoisoningNextDispatch(): void
    {
        $app = $this->buildApp(new ParallelConfig(agents: 1));

        try {
            $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
                $scope = $app->createScope();

                try {
                    self::assertSame('stderr-drained', $scope->inWorker(new StderrTask('worker-warning')));
                    self::assertSame(5, $scope->inWorker(new AddNumbers(2, 3)));
                } finally {
                    $scope->dispose();
                }
            });

            $stderrEvents = array_filter(
                $app->trace()->events(),
                static fn($e) => $e->name === 'worker.worker.stderr',
            );
            $chunks = implode('', array_map(static fn($e) => $e->attrs['chunk'] ?? '', $stderrEvents));

            self::assertStringContainsString('worker-warning', $chunks);
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function cancelledWorkerDispatchRestartsCleanlyForNextTask(): void
    {
        $app = $this->buildApp(new ParallelConfig(agents: 1));

        try {
            $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
                $scope = $app->createScope();

                try {
                    try {
                        $scope->timeout(
                            Mark::ms(50),
                            Task::of(static fn(ExecutionScope $s): mixed => $s->inWorker(new SlowTask(250_000))),
                        );
                        self::fail('Expected worker timeout to cancel the in-flight dispatch.');
                    } catch (Cancelled $e) {
                        self::assertSame('timeout after 50ms', $e->getMessage());
                    }
                } finally {
                    $scope->dispose();
                }
            });

            $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
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
            $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
                $scope = $app->createScope();

                try {
                    $settled = $scope->settle(
                        busy: Task::of(
                            static fn(ExecutionScope $s): mixed => $s->inWorker(new SlowTask(250_000)),
                        ),
                        waiter: Task::of(
                            static fn(ExecutionScope $s): mixed => $s->timeout(
                                Mark::ms(50),
                                Task::of(
                                    static fn(ExecutionScope $t): mixed => $t->inWorker(new AddNumbers(2, 3)),
                                ),
                            ),
                        ),
                    );

                    $waiterError = $settled->errors['waiter'] ?? null;

                    self::assertSame('slow-done', $settled->get('busy'));
                    self::assertInstanceOf(Cancelled::class, $waiterError);
                    self::assertStringContainsString(
                        'timeout after 50ms',
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
            $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
                $scope = $app->createScope();

                try {
                    self::assertSame(1, $scope->inWorker(new StatefulCounterTask()));
                } finally {
                    $scope->dispose();
                }
            });

            $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
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

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
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
    public function serviceBundleSuppliesWorkerDispatch(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(
                GreetingService::class,
                static fn(): GreetingService => new GreetingServiceImpl(),
            );

        $testApp = $this->testApp([], $bundle, Worker::services(new ParallelConfig(agents: 1)));
        $app = $testApp->application->startup();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            $scope = $app->createScope();

            try {
                self::assertSame(5, $scope->inWorker(new AddNumbers(2, 3)));
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function parallelTasksExecuteConcurrently(): void
    {
        $app = $this->app;

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            $scope = $app->createScope();

            try {
                $results = $scope->execute(Task::of(
                    static fn(ExecutionScope $es): array => $es->concurrent(
                        a: Task::of(static fn(ExecutionScope $s): mixed => $s->inWorker(new AddNumbers(1, 2))),
                        b: Task::of(static fn(ExecutionScope $s): mixed => $s->inWorker(new AddNumbers(3, 4))),
                    )
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

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
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

    private function buildApp(ParallelConfig $config): Application
    {
        $app = $this->buildTestApp($config)->application;
        $app->startup();

        return $app;
    }

    private function buildTestApp(ParallelConfig $config): TestApp
    {
        $bundle = TestServiceBundle::create()
            ->singleton(
                GreetingService::class,
                static fn(): GreetingService => new GreetingServiceImpl(),
            );

        return $this->testApp([], $bundle, new Bundle($config));
    }
}
