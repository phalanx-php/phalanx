<?php

declare(strict_types=1);

namespace Phalanx\Tests\Smoke;

use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Exception\CancelledException;
use Phalanx\ExecutionScope;
use Phalanx\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Retryable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Testing\Assert;
use Phalanx\Testing\Probe\ConcurrencyProbe;
use Phalanx\Testing\Probe\InterleavingProbe;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('smoke')]
final class ConcurrentWorkloadTest extends TestCase
{
    #[Test]
    public function concurrent_all_with_varying_delays(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $start = hrtime(true);

            $results = $scope->concurrent([
                'fast' => Task::of(static function (ExecutionScope $es): string {
                    $es->delay(0.01);
                    return 'fast_result';
                }),
                'medium' => Task::of(static function (ExecutionScope $es): string {
                    $es->delay(0.03);
                    return 'medium_result';
                }),
                'slow' => Task::of(static function (ExecutionScope $es): string {
                    $es->delay(0.02);
                    return 'slow_result';
                }),
            ]);

            $elapsed = (hrtime(true) - $start) / 1e6;

            Assert::assertSame('fast_result', $results['fast']);
            Assert::assertSame('medium_result', $results['medium']);
            Assert::assertSame('slow_result', $results['slow']);
            Assert::assertElapsedBelow($elapsed, 100);
        });
    }

    #[Test]
    public function settle_captures_mixed_results(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $settlements = $scope->settle([
                'success1' => Task::of(static fn() => 'ok1'),
                'failure1' => Task::of(static fn() => throw new RuntimeException('fail1')),
                'success2' => Task::of(static fn() => 'ok2'),
                'failure2' => Task::of(static fn() => throw new RuntimeException('fail2')),
            ]);

            Assert::assertSettled($settlements, [
                'success1' => Assert::ok('ok1'),
                'failure1' => Assert::failed(RuntimeException::class, 'fail1'),
                'success2' => Assert::ok('ok2'),
                'failure2' => Assert::failed(RuntimeException::class, 'fail2'),
            ]);
        });
    }

    #[Test]
    public function race_returns_fastest(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $result = $scope->race([
                Task::of(static function (ExecutionScope $es): string {
                    $es->delay(0.1);
                    return 'slow';
                }),
                Task::of(static function (ExecutionScope $es): string {
                    $es->delay(0.005);
                    return 'fast';
                }),
                Task::of(static function (ExecutionScope $es): string {
                    $es->delay(0.05);
                    return 'medium';
                }),
            ]);

            Assert::assertSame('fast', $result);
        });
    }

    #[Test]
    public function any_ignores_failures_returns_first_success(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $result = $scope->any([
                Task::of(static fn() => throw new RuntimeException('immediate_fail')),
                Task::of(static function (ExecutionScope $es): string {
                    $es->delay(0.02);
                    return 'delayed_success';
                }),
                Task::of(static function (ExecutionScope $es): never {
                    $es->delay(0.01);
                    throw new RuntimeException('delayed_fail');
                }),
            ]);

            Assert::assertSame('delayed_success', $result);
        });
    }

    #[Test]
    public function map_with_bounded_concurrency(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $probe = new ConcurrencyProbe();

            $results = $scope->map(
                range(1, 10),
                static function (int $item) use ($probe): Scopeable {
                    return Task::of(static function (ExecutionScope $es) use ($item, $probe): int {
                        $probe->enter();
                        $es->delay(0.01);
                        $probe->exit();
                        return $item * 2;
                    });
                },
                limit: 3,
            );

            Assert::assertEquals([2, 4, 6, 8, 10, 12, 14, 16, 18, 20], array_values($results));
            Assert::assertConcurrencyBound($probe, 3);
        });
    }

    #[Test]
    public function timeout_cancels_long_running_task(): void
    {
        $threw = false;

        try {
            TestScope::run(static function (ExecutionScope $scope): void {
                $scope->timeout(0.01, Task::of(static function (ExecutionScope $es): string {
                    $es->delay(1.0);
                    return 'should_not_complete';
                }));
            });
        } catch (CancelledException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected CancelledException');
    }

    #[Test]
    public function retry_with_transient_failure(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
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

            Assert::assertSame('finally_succeeded', $result);
            Assert::assertSame(3, $attempts);
        });
    }

    #[Test]
    public function timeout_via_interface(): void
    {
        $threw = false;

        try {
            TestScope::run(static function (ExecutionScope $scope): void {
                $task = new class implements Executable, HasTimeout {
                    public float $timeout {
                        get => 0.01;
                    }

                    public function __invoke(ExecutionScope $scope): string
                    {
                        $scope->delay(1.0);
                        return 'should_not_complete';
                    }
                };

                $scope->execute($task);
            });
        } catch (CancelledException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected CancelledException');
    }

    #[Test]
    public function series_maintains_order(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $order = [];

            $results = $scope->series([
                Task::of(static function (ExecutionScope $es) use (&$order): string {
                    $es->delay(0.02);
                    $order[] = 1;
                    return 'first';
                }),
                Task::of(static function (ExecutionScope $es) use (&$order): string {
                    $es->delay(0.01);
                    $order[] = 2;
                    return 'second';
                }),
                Task::of(static function () use (&$order): string {
                    $order[] = 3;
                    return 'third';
                }),
            ]);

            Assert::assertEquals([1, 2, 3], $order);
            Assert::assertEquals(['first', 'second', 'third'], $results);
        });
    }

    #[Test]
    public function defer_executes_without_blocking(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $deferred = false;
            $mainCompleted = false;

            $scope->defer(Task::of(static function (ExecutionScope $es) use (&$deferred): void {
                $es->delay(0.02);
                $deferred = true;
            }));

            $mainCompleted = true;

            Assert::assertTrue($mainCompleted);
            Assert::assertFalse($deferred);

            $scope->delay(0.05);

            Assert::assertTrue($deferred);
        });
    }

    #[Test]
    public function nested_concurrent_operations(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $probe = new InterleavingProbe();

            $results = $scope->concurrent([
                'batch1' => Task::of(static function (ExecutionScope $es) use ($probe): array {
                    $probe->checkpoint('batch1:start');
                    $es->delay(0.01);
                    $result = $es->concurrent([
                        'a' => Task::of(static fn() => 'a'),
                        'b' => Task::of(static fn() => 'b'),
                    ]);
                    $probe->checkpoint('batch1:end');
                    return $result;
                }),
                'batch2' => Task::of(static function (ExecutionScope $es) use ($probe): array {
                    $probe->checkpoint('batch2:start');
                    $es->delay(0.01);
                    $result = $es->concurrent([
                        'c' => Task::of(static fn() => 'c'),
                        'd' => Task::of(static fn() => 'd'),
                    ]);
                    $probe->checkpoint('batch2:end');
                    return $result;
                }),
            ]);

            Assert::assertSame(['a' => 'a', 'b' => 'b'], $results['batch1']);
            Assert::assertSame(['c' => 'c', 'd' => 'd'], $results['batch2']);
            $probe->assertInterleaved('batch1', 'batch2');
        });
    }
}
