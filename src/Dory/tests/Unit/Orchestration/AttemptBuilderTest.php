<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit\Orchestration;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Dory\Orchestration\AttemptBuilder;
use Phalanx\Scope\ExecutionScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AttemptBuilderTest extends TestCase
{
    #[Test]
    public function bare_run_executes_task(): void
    {
        $scope = $this->mockScope(expectedResult: 'apollo');

        $builder = new AttemptBuilder($scope, static fn(): string => 'apollo');
        $result = $builder->run();

        self::assertSame('apollo', $result);
    }

    #[Test]
    public function timeout_wraps_execution(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $scope->expects(self::once())
            ->method('timeout')
            ->with(5.0, self::isInstanceOf(Closure::class))
            ->willReturn('poseidon');

        $scope->method('execute')
            ->willReturn('poseidon');

        $builder = new AttemptBuilder($scope, static fn(): string => 'poseidon');
        $result = $builder->timeout(5.0)->run();

        self::assertSame('poseidon', $result);
    }

    #[Test]
    public function retry_wraps_execution(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $scope->expects(self::once())
            ->method('retry')
            ->with(self::isInstanceOf(Closure::class), self::isInstanceOf(RetryPolicy::class))
            ->willReturn('demeter');

        $scope->method('execute')
            ->willReturn('demeter');

        $builder = new AttemptBuilder($scope, static fn(): string => 'demeter');
        $result = $builder->retry(3)->run();

        self::assertSame('demeter', $result);
    }

    #[Test]
    public function singleflight_wraps_execution(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $scope->expects(self::once())
            ->method('singleflight')
            ->with('marathon', self::isInstanceOf(Closure::class))
            ->willReturn('victory');

        $scope->method('execute')
            ->willReturn('victory');

        $builder = new AttemptBuilder($scope, static fn(): string => 'victory');
        $result = $builder->singleflight('marathon')->run();

        self::assertSame('victory', $result);
    }

    #[Test]
    public function catch_handles_exception(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $scope->method('execute')
            ->willThrowException(new RuntimeException('phalanx broken'));

        $builder = new AttemptBuilder($scope, static fn(): string => throw new RuntimeException('phalanx broken'));
        $result = $builder->catch(static fn(RuntimeException $e): string => 'recovered: ' . $e->getMessage())->run();

        self::assertSame('recovered: phalanx broken', $result);
    }

    #[Test]
    public function finally_runs_on_success(): void
    {
        $scope = $this->mockScope(expectedResult: 'hera');
        $finalized = false;

        $builder = new AttemptBuilder($scope, static fn(): string => 'hera');
        $result = $builder->finally(static function () use (&$finalized): void {
            $finalized = true;
        })->run();

        self::assertSame('hera', $result);
        self::assertTrue($finalized);
    }

    #[Test]
    public function finally_runs_on_failure(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $scope->method('execute')
            ->willThrowException(new RuntimeException('fall'));
        $finalized = false;

        $builder = new AttemptBuilder($scope, static fn(): string => throw new RuntimeException('fall'));

        try {
            $builder->finally(static function () use (&$finalized): void {
                $finalized = true;
            })->run();
        } catch (RuntimeException) {
        }

        self::assertTrue($finalized);
    }

    #[Test]
    public function bare_run_propagates_exception(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $scope->method('execute')
            ->willThrowException(new RuntimeException('sarissa snapped'));

        $builder = new AttemptBuilder($scope, static fn(): string => throw new RuntimeException('sarissa snapped'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sarissa snapped');

        $builder->run();
    }

    #[Test]
    public function retry_timeout_catch_combined(): void
    {
        $scope = $this->createStub(ExecutionScope::class);

        $scope->method('singleflight')
            ->willReturnCallback(static fn(string $key, Closure $task): mixed => $task());

        $scope->method('timeout')
            ->willReturnCallback(static fn(float $seconds, Closure $task): mixed => $task());

        $scope->method('retry')
            ->willReturnCallback(static function (Closure $task, RetryPolicy $policy): mixed {
                try {
                    return $task();
                } catch (RuntimeException) {
                    return $task();
                }
            });

        $attempts = 0;
        $scope->method('execute')
            ->willReturnCallback(static function () use (&$attempts): string {
                $attempts++;
                if ($attempts === 1) {
                    throw new RuntimeException('first attempt fails');
                }
                return 'recovered';
            });

        $builder = new AttemptBuilder($scope, static fn(): string => 'ignored');
        $result = $builder
            ->retry(3)
            ->timeout(10.0)
            ->singleflight('polis')
            ->catch(static fn(RuntimeException $e): string => 'caught: ' . $e->getMessage())
            ->run();

        self::assertSame('recovered', $result);
    }

    #[Test]
    public function combined_nesting_order(): void
    {
        $callOrder = [];

        $scope = $this->createStub(ExecutionScope::class);

        $scope->method('singleflight')
            ->willReturnCallback(static function (string $key, Closure $task) use (&$callOrder): mixed {
                $callOrder[] = 'singleflight';
                return $task();
            });

        $scope->method('timeout')
            ->willReturnCallback(static function (float $seconds, Closure $task) use (&$callOrder): mixed {
                $callOrder[] = 'timeout';
                return $task();
            });

        $scope->method('retry')
            ->willReturnCallback(static function (Closure $task, RetryPolicy $policy) use (&$callOrder): mixed {
                $callOrder[] = 'retry';
                return $task();
            });

        $scope->method('execute')
            ->willReturn('ares');

        $builder = new AttemptBuilder($scope, static fn(): string => 'ares');
        $result = $builder
            ->retry(3)
            ->timeout(10.0)
            ->singleflight('war')
            ->run();

        self::assertSame('ares', $result);
        self::assertSame(['singleflight', 'timeout', 'retry'], $callOrder);
    }

    #[Test]
    public function cancelled_propagates_through_catch_handler(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $scope->method('execute')
            ->willThrowException(new Cancelled('scope ended'));

        $catchCalled = false;

        $builder = new AttemptBuilder($scope, static fn(): string => 'ignored');
        $builder->catch(static function () use (&$catchCalled): string {
            $catchCalled = true;
            return 'swallowed';
        });

        $this->expectException(Cancelled::class);

        try {
            $builder->run();
        } finally {
            self::assertFalse($catchCalled);
        }
    }

    private function mockScope(mixed $expectedResult): ExecutionScope
    {
        $scope = $this->createStub(ExecutionScope::class);
        $scope->method('execute')
            ->willReturn($expectedResult);

        return $scope;
    }
}
