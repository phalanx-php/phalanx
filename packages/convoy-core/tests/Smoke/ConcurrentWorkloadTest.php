<?php

declare(strict_types=1);

namespace Convoy\Tests\Smoke;

use Convoy\Application;
use Convoy\Concurrency\RetryPolicy;
use Convoy\Concurrency\Settlement;
use Convoy\Exception\CancelledException;
use Convoy\ExecutionScope;
use Convoy\Scope;
use Convoy\Task\Executable;
use Convoy\Task\HasTimeout;
use Convoy\Task\Retryable;
use Convoy\Task\Scopeable;
use Convoy\Task\Task;
use Convoy\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function React\Async\delay;

final class ConcurrentWorkloadTest extends AsyncTestCase
{
    #[Test]
    public function concurrent_all_with_varying_delays(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();
            $start = hrtime(true);

            $results = $scope->concurrent([
                'fast' => Task::of(static function () {
                    delay(0.01);
                    return 'fast_result';
                }),
                'medium' => Task::of(static function () {
                    delay(0.03);
                    return 'medium_result';
                }),
                'slow' => Task::of(static function () {
                    delay(0.02);
                    return 'slow_result';
                }),
            ]);

            $elapsed = (hrtime(true) - $start) / 1e6;

            $this->assertSame('fast_result', $results['fast']);
            $this->assertSame('medium_result', $results['medium']);
            $this->assertSame('slow_result', $results['slow']);
            $this->assertLessThan(100, $elapsed);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function settle_captures_mixed_results(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $settlements = $scope->settle([
                'success1' => Task::of(static fn() => 'ok1'),
                'failure1' => Task::of(static fn() => throw new RuntimeException('fail1')),
                'success2' => Task::of(static fn() => 'ok2'),
                'failure2' => Task::of(static fn() => throw new RuntimeException('fail2')),
            ]);

            $this->assertTrue($settlements['success1']->isOk);
            $this->assertSame('ok1', $settlements['success1']->value);

            $this->assertFalse($settlements['failure1']->isOk);
            $this->assertInstanceOf(RuntimeException::class, $settlements['failure1']->error);
            $this->assertSame('fail1', $settlements['failure1']->error->getMessage());

            $this->assertTrue($settlements['success2']->isOk);
            $this->assertSame('ok2', $settlements['success2']->value);

            $this->assertFalse($settlements['failure2']->isOk);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function race_returns_fastest(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->race([
                Task::of(static function () {
                    delay(0.1);
                    return 'slow';
                }),
                Task::of(static function () {
                    delay(0.005);
                    return 'fast';
                }),
                Task::of(static function () {
                    delay(0.05);
                    return 'medium';
                }),
            ]);

            $this->assertSame('fast', $result);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function any_ignores_failures_returns_first_success(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->any([
                Task::of(static fn() => throw new RuntimeException('immediate_fail')),
                Task::of(static function () {
                    delay(0.02);
                    return 'delayed_success';
                }),
                Task::of(static function () {
                    delay(0.01);
                    throw new RuntimeException('delayed_fail');
                }),
            ]);

            $this->assertSame('delayed_success', $result);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function map_with_bounded_concurrency(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();
            $maxConcurrent = 0;
            $currentConcurrent = 0;

            $results = $scope->map(
                range(1, 10),
                function (int $item) use (&$maxConcurrent, &$currentConcurrent) {
                    return Task::of(static function () use ($item, &$maxConcurrent, &$currentConcurrent) {
                        $currentConcurrent++;
                        $maxConcurrent = max($maxConcurrent, $currentConcurrent);

                        delay(0.01);

                        $currentConcurrent--;
                        return $item * 2;
                    });
                },
                limit: 3,
            );

            $this->assertEquals([2, 4, 6, 8, 10, 12, 14, 16, 18, 20], array_values($results));
            $this->assertLessThanOrEqual(3, $maxConcurrent);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function timeout_cancels_long_running_task(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $this->expectException(CancelledException::class);

            $scope->timeout(0.01, Task::of(static function () {
                delay(1.0);
                return 'should_not_complete';
            }));

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function retry_with_transient_failure(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();
            $attempts = 0;

            $task = new class($attempts) implements Scopeable, Retryable {
                public RetryPolicy $retryPolicy {
                    get => RetryPolicy::fixed(3, 5);
                }

                public function __construct(private int &$attempts) {}

                public function __invoke(Scope $scope): string
                {
                    $this->attempts++;
                    if ($this->attempts < 3) {
                        throw new RuntimeException("Transient failure #" . $this->attempts);
                    }
                    return 'finally_succeeded';
                }
            };

            $result = $scope->execute($task);

            $this->assertSame('finally_succeeded', $result);
            $this->assertSame(3, $attempts);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function timeout_via_interface(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $task = new class implements Executable, HasTimeout {
                public float $timeout {
                    get => 0.01;
                }

                public function __invoke(ExecutionScope $scope): string
                {
                    delay(1.0);
                    return 'should_not_complete';
                }
            };

            $this->expectException(CancelledException::class);
            $scope->execute($task);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function series_maintains_order(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();
            $order = [];

            $results = $scope->series([
                Task::of(static function () use (&$order) {
                    delay(0.02);
                    $order[] = 1;
                    return 'first';
                }),
                Task::of(static function () use (&$order) {
                    delay(0.01);
                    $order[] = 2;
                    return 'second';
                }),
                Task::of(static function () use (&$order) {
                    $order[] = 3;
                    return 'third';
                }),
            ]);

            $this->assertEquals([1, 2, 3], $order);
            $this->assertEquals(['first', 'second', 'third'], $results);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function defer_executes_without_blocking(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();
            $deferred = false;
            $mainCompleted = false;

            $scope->defer(Task::of(static function () use (&$deferred) {
                delay(0.02);
                $deferred = true;
            }));

            $mainCompleted = true;

            $this->assertTrue($mainCompleted);
            $this->assertFalse($deferred);

            delay(0.05);

            $this->assertTrue($deferred);

            $scope->dispose();
        });

        $app->shutdown();
    }

    #[Test]
    public function nested_concurrent_operations(): void
    {
        $app = Application::starting()->compile();
        $app->startup();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $results = $scope->concurrent([
                'batch1' => Task::of(static fn(ExecutionScope $es) => $es->concurrent([
                    'a' => Task::of(static fn() => 'a'),
                    'b' => Task::of(static fn() => 'b'),
                ])),
                'batch2' => Task::of(static fn(ExecutionScope $es) => $es->concurrent([
                    'c' => Task::of(static fn() => 'c'),
                    'd' => Task::of(static fn() => 'd'),
                ])),
            ]);

            $this->assertSame(['a' => 'a', 'b' => 'b'], $results['batch1']);
            $this->assertSame(['c' => 'c', 'd' => 'd'], $results['batch2']);

            $scope->dispose();
        });

        $app->shutdown();
    }
}
