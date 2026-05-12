<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

use Phalanx\Pool\ManagedPool;
use Phalanx\Pool\ManagedPoolFactory;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Trace\Trace;
use RuntimeException;

/**
 * @implements ManagedPoolFactory<TestPoolClient>
 */
final class ManagedPoolTestFactory implements ManagedPoolFactory
{
    public static function make(mixed $config): TestPoolClient
    {
        return new TestPoolClient();
    }
}

final class TestPoolClient
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
