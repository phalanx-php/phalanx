<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Stream;

use Convoy\Application;
use Convoy\Task\LazySequence;
use Convoy\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

final class LazySequenceStreamTest extends AsyncTestCase
{
    #[Test]
    public function lifecycle_hooks_fire_in_correct_order(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $log = [];

        $seq = LazySequence::of([1, 2, 3])
            ->onStart(static function () use (&$log): void { $log[] = 'start'; })
            ->onEach(static function ($v) use (&$log): void { $log[] = "each:$v"; })
            ->onComplete(static function () use (&$log): void { $log[] = 'complete'; })
            ->onDispose(static function () use (&$log): void { $log[] = 'dispose'; });

        $result = $scope->execute($seq->toArray());

        $this->assertSame([1, 2, 3], $result);
        $this->assertSame(['start', 'each:1', 'each:2', 'each:3', 'complete', 'dispose'], $log);
    }

    #[Test]
    public function error_hook_fires_on_exception(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $errorLog = [];

        $seq = LazySequence::from(static function (): \Generator {
            yield 1;
            throw new \RuntimeException('boom');
        })->onError(static function (\Throwable $e) use (&$errorLog): void {
            $errorLog[] = $e->getMessage();
        });

        try {
            $scope->execute($seq->toArray());
        } catch (\RuntimeException) {
        }

        $this->assertSame(['boom'], $errorLog);
    }

    #[Test]
    public function hooks_propagate_through_operators(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $log = [];

        $seq = LazySequence::of([1, 2, 3, 4])
            ->onStart(static function () use (&$log): void { $log[] = 'start'; })
            ->onComplete(static function () use (&$log): void { $log[] = 'complete'; })
            ->filter(static fn(int $v): bool => $v % 2 === 0)
            ->map(static fn(int $v): int => $v * 10);

        $result = $scope->execute($seq->toArray());

        $this->assertSame([20, 40], array_values($result));
        $this->assertContains('start', $log);
        $this->assertContains('complete', $log);
    }

    #[Test]
    public function drain_terminal_processes_without_accumulating(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $processed = [];

        $seq = LazySequence::of(['a', 'b', 'c'])
            ->onEach(static function ($v) use (&$processed): void {
                $processed[] = $v;
            });

        $result = $scope->execute($seq->consume());

        $this->assertNull($result);
        $this->assertSame(['a', 'b', 'c'], $processed);
    }

    #[Test]
    public function existing_operators_still_work(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $seq = LazySequence::of(range(1, 10))
            ->filter(static fn(int $v): bool => $v > 5)
            ->map(static fn(int $v): int => $v * 2)
            ->take(3);

        $result = $scope->execute($seq->toArray());

        $this->assertSame([12, 14, 16], array_values($result));
    }

    #[Test]
    public function reduce_terminal_works(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $seq = LazySequence::of([1, 2, 3, 4, 5]);
        $result = $scope->execute($seq->reduce(static fn(int $acc, int $v): int => $acc + $v, 0));

        $this->assertSame(15, $result);
    }

    #[Test]
    public function first_terminal_works(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $seq = LazySequence::of([10, 20, 30]);
        $result = $scope->execute($seq->first());

        $this->assertSame(10, $result);
    }

    #[Test]
    public function chunk_operator_works(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $seq = LazySequence::of([1, 2, 3, 4, 5])->chunk(2);
        $result = $scope->execute($seq->toArray());

        $this->assertSame([[1, 2], [3, 4], [5]], $result);
    }

    #[Test]
    public function map_concurrent_works(): void
    {
        $this->runAsync(function (): void {
            $app = Application::starting()->compile();
            $scope = $app->createScope();

            $seq = LazySequence::of([1, 2, 3])
                ->mapConcurrent(static fn(int $v): int => $v * 10, 2);

            $result = $scope->execute($seq->toArray());

            $this->assertSame([10, 20, 30], $result);
        });
    }

    #[Test]
    public function callable_types_accepted(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $doubler = new class () {
            public function __invoke(int $v): int
            {
                return $v * 2;
            }
        };

        $seq = LazySequence::of([1, 2, 3])->map($doubler);
        $result = $scope->execute($seq->toArray());

        $this->assertSame([2, 4, 6], $result);
    }
}
