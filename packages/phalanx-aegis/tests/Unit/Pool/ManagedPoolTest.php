<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Pool\ManagedPoolClient;
use Phalanx\Pool\ManagedPool;
use Phalanx\Pool\ManagedPoolFactory;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\LeaseViolation;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Task\Task;
use Phalanx\Trace\Trace;
use RuntimeException;

/**
 * @implements ManagedPoolFactory<TestPoolClient>
 */
final class ManagedPoolTestFactory implements ManagedPoolFactory
{
    public static function make(mixed $config): ManagedPoolClient
    {
        return new TestPoolClient();
    }
}

final class TestPoolClient
    implements ManagedPoolClient
{
    public int $useCount = 0;

    public function close(): void
    {
    }
}

/**
 * ManagedPool composes OpenSwoole core's ClientPool. The pool itself
 * requires the OpenSwoole coroutine runtime (Channel-backed acquire) so
 * tests run inside the coroutine harness.
 *
 * Starvation-diagnostic exercise lives in integration tests where multi-
 * coroutine contention can be reliably staged; unit coverage focuses on
 * the lease lifecycle and the use() try/finally guarantee.
 */
final class ManagedPoolTest extends PhalanxTestCase
{
    public function testAcquireReleaseRoundTrip(): void
    {
        $trace = new Trace();
        $pool = new ManagedPool(
            domain: 'test/pool',
            factoryClass: ManagedPoolTestFactory::class,
            config: null,
            trace: $trace,
            size: 2,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($pool): void {
            $lease = $pool->acquire($scope);
            self::assertSame('test/pool', $lease->domain);
            self::assertSame('shared', $lease->mode);
            $pool->release($lease);
        });
    }

    public function testUseGuaranteesReleaseOnException(): void
    {
        $trace = new Trace();
        $pool = new ManagedPool(
            domain: 'test/use',
            factoryClass: ManagedPoolTestFactory::class,
            config: null,
            trace: $trace,
            size: 1,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($pool): void {
            $threw = false;

            try {
                $pool->use($scope, static function (object $client): void {
                    self::assertInstanceOf(TestPoolClient::class, $client);
                    $client->useCount++;

                    if ($client->useCount === 1) {
                        throw new RuntimeException('boom');
                    }
                });
            } catch (RuntimeException $e) {
                $threw = true;
                self::assertSame('boom', $e->getMessage());
            }
            self::assertTrue($threw);

            // The pool returns the same single client immediately —
            // proving release fired in the finally arm.
            $lease = $pool->acquire($scope, timeout: 0.5);
            self::assertSame('test/use', $lease->domain);
            $pool->release($lease);
        });
    }

    public function testAcquireRegistersAndReleasesSupervisorLease(): void
    {
        $pool = new ManagedPool(
            domain: 'test/lease',
            factoryClass: ManagedPoolTestFactory::class,
            config: null,
            trace: new Trace(),
            size: 1,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($pool): void {
            $lease = $pool->acquire($scope);
            $snapshot = $scope->currentRunSnapshot();

            self::assertNotNull($snapshot);
            self::assertCount(1, $snapshot->leases);
            self::assertSame('test/lease', $snapshot->leases[0]['domain']);

            $pool->release($lease);
            $snapshot = $scope->currentRunSnapshot();

            self::assertNotNull($snapshot);
            self::assertCount(0, $snapshot->leases);
        });
    }

    public function testNestedAcquireReturnsClientAndReportsLeaseViolation(): void
    {
        $pool = new ManagedPool(
            domain: 'test/nested',
            factoryClass: ManagedPoolTestFactory::class,
            config: null,
            trace: new Trace(),
            size: 2,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($pool): void {
            $lease = $pool->acquire($scope);

            try {
                $thrown = null;
                try {
                    $pool->acquire($scope);
                } catch (LeaseViolation $e) {
                    $thrown = $e;
                }

                self::assertNotNull($thrown);
                self::assertSame('PHX-POOL-001', $thrown->phxCode);
            } finally {
                $pool->release($lease);
            }

            $lease = $pool->acquire($scope);
            $pool->release($lease);
        });
    }

    public function testAcquireTimeoutDoesNotPoisonClose(): void
    {
        $pool = new ManagedPool(
            domain: 'test/timeout',
            factoryClass: ManagedPoolTestFactory::class,
            config: null,
            trace: new Trace(),
            size: 1,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($pool): void {
            $lease = $pool->acquire($scope);

            try {
                $thrown = null;
                try {
                    $pool->acquire($scope, timeout: 0.001);
                } catch (RuntimeException $e) {
                    $thrown = $e;
                }

                self::assertNotNull($thrown);
                self::assertStringContainsString('timeout or closed', $thrown->getMessage());
            } finally {
                $pool->release($lease);
            }
        });

        $pool->close();
    }

    public function testCancelledAcquireDoesNotPoisonClose(): void
    {
        $pool = new ManagedPool(
            domain: 'test/cancel',
            factoryClass: ManagedPoolTestFactory::class,
            config: null,
            trace: new Trace(),
            size: 1,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($pool): void {
            $lease = $pool->acquire($scope);

            try {
                $thrown = null;
                try {
                    $scope->timeout(
                        0.01,
                        Task::of(static fn(ExecutionScope $child): mixed => $pool->acquire($child)),
                    );
                } catch (Cancelled $e) {
                    $thrown = $e;
                }

                self::assertNotNull($thrown);
            } finally {
                $pool->release($lease);
            }
        });

        $pool->close();
    }

    public function testPoolReportsConfiguredSize(): void
    {
        $pool = new ManagedPool(
            domain: 'test/sized',
            factoryClass: ManagedPoolTestFactory::class,
            config: null,
            trace: new Trace(),
            size: 4,
        );

        self::assertSame(4, $pool->size);
    }

    public function testCloseCanRunOutsideCoroutineAfterCheckout(): void
    {
        $pool = new ManagedPool(
            domain: 'test/close',
            factoryClass: ManagedPoolTestFactory::class,
            config: null,
            trace: new Trace(),
            size: 1,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($pool): void {
            $pool->use($scope, static function (object $client): int {
                self::assertInstanceOf(TestPoolClient::class, $client);

                return ++$client->useCount;
            });
        });

        $pool->close();
        $pool->close();

        self::assertSame(1, $pool->size);
    }

    public function testAcquireAfterCloseFailsClearly(): void
    {
        $pool = new ManagedPool(
            domain: 'test/closed',
            factoryClass: ManagedPoolTestFactory::class,
            config: null,
            trace: new Trace(),
            size: 1,
        );
        $pool->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ManagedPool(test/closed)::acquire(): pool is closed');

        $this->scope->run(static function (ExecutionScope $scope) use ($pool): void {
            $pool->acquire($scope);
        });
    }
}
