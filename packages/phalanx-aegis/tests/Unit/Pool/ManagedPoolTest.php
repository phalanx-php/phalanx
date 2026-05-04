<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

use Phalanx\Pool\ManagedPool;
use Phalanx\Pool\ManagedPoolFactory;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Tests\Support\CoroutineTestCase;
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
final class ManagedPoolTest extends CoroutineTestCase
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

        $this->runScoped(static function (ExecutionScope $scope) use ($pool): void {
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

        $this->runScoped(static function (ExecutionScope $scope) use ($pool): void {
            try {
                $pool->use($scope, static function (TestPoolClient $client): void {
                    $client->useCount++;
                    throw new RuntimeException('boom');
                });
                self::fail('expected exception');
            } catch (RuntimeException $e) {
                self::assertSame('boom', $e->getMessage());
            }

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
}
