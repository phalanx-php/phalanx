<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Scope;

use Convoy\Application;
use Convoy\ExecutionScope;
use Convoy\Task\Task;
use Convoy\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

use function React\Async\delay;

final class ScopeConcurrencyTest extends AsyncTestCase
{
    #[Test]
    public function concurrent_executes_cooperatively(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();
            $start = hrtime(true);

            $results = $scope->concurrent([
                'a' => Task::of(static function () {
                    delay(0.05);
                    return 'result_a';
                }),
                'b' => Task::of(static function () {
                    delay(0.05);
                    return 'result_b';
                }),
                'c' => Task::of(static function () {
                    delay(0.05);
                    return 'result_c';
                }),
            ]);

            $elapsed = (hrtime(true) - $start) / 1e6;

            $this->assertSame('result_a', $results['a']);
            $this->assertSame('result_b', $results['b']);
            $this->assertSame('result_c', $results['c']);
            $this->assertLessThan(150, $elapsed);
        });
    }

    #[Test]
    public function map_respects_concurrency_limit(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $results = $scope->map(
                range(1, 5),
                fn(int $item) => Task::of(static fn() => $item * 2),
                limit: 2,
            );

            $this->assertEquals([2, 4, 6, 8, 10], array_values($results));
        });
    }

    #[Test]
    public function series_executes_sequentially(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();
            $order = [];

            $results = $scope->series([
                Task::of(static function () use (&$order) {
                    $order[] = 1;
                    return 'a';
                }),
                Task::of(static function () use (&$order) {
                    $order[] = 2;
                    return 'b';
                }),
            ]);

            $this->assertEquals([1, 2], $order);
            $this->assertEquals(['a', 'b'], $results);
        });
    }

    #[Test]
    public function settle_collects_all_outcomes(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $settlements = $scope->settle([
                'success' => Task::of(static fn() => 'ok'),
                'failure' => Task::of(static fn() => throw new \RuntimeException('fail')),
            ]);

            $this->assertTrue($settlements['success']->isOk);
            $this->assertSame('ok', $settlements['success']->value);
            $this->assertFalse($settlements['failure']->isOk);
        });
    }

    #[Test]
    public function race_returns_first_to_complete(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->race([
                Task::of(static function () {
                    delay(0.1);
                    return 'slow';
                }),
                Task::of(static function () {
                    delay(0.01);
                    return 'fast';
                }),
            ]);

            $this->assertSame('fast', $result);
        });
    }

    #[Test]
    public function any_returns_first_success(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->any([
                Task::of(static fn() => throw new \RuntimeException('fail1')),
                Task::of(static function () {
                    delay(0.02);
                    return 'success';
                }),
                Task::of(static fn() => throw new \RuntimeException('fail2')),
            ]);

            $this->assertSame('success', $result);
        });
    }

    #[Test]
    public function waterfall_passes_result_via_attribute(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->waterfall([
                Task::of(static fn() => 1),
                Task::of(static fn(ExecutionScope $es) => $es->attribute('_waterfall_previous') + 10),
                Task::of(static fn(ExecutionScope $es) => $es->attribute('_waterfall_previous') * 2),
            ]);

            $this->assertSame(22, $result);
        });
    }

    #[Test]
    public function defer_fires_and_forgets(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();
            $executed = false;

            $scope->defer(Task::of(static function () use (&$executed) {
                $executed = true;
            }));

            delay(0.05);

            $this->assertTrue($executed);
        });
    }

    #[Test]
    public function withAttribute_creates_child_scope(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $child = $scope->withAttribute('key', 'value');

        $this->assertNull($scope->attribute('key'));
        $this->assertSame('value', $child->attribute('key'));
    }

    #[Test]
    public function timeout_throws_on_exceeded(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $this->expectException(\Convoy\Exception\CancelledException::class);

            $scope->timeout(0.01, Task::of(static function () {
                delay(1.0);
                return 'never';
            }));
        });
    }
}
