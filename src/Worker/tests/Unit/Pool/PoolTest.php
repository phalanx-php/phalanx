<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Unit\Pool;

use Phalanx\Worker\Pool\Pool;
use PHPUnit\Framework\TestCase;

/**
 * The actual fork/exec lifecycle of `Swoole\Process\Pool` is not
 * exercised in unit tests because `start()` blocks the calling process
 * until SIGTERM. Integration coverage runs in a forked test process.
 *
 * Unit coverage focuses on the configuration-time API: that worker
 * factories accumulate, that the convenience `ofSize()` factory composes
 * the right shape, and that the count reflects what was registered.
 */
final class PoolTest extends TestCase
{
    public function testAddAccumulatesWorkerCount(): void
    {
        $pool = new Pool();
        $pool->add(static function (): void {
        });
        $pool->add(static function (): void {
        });

        self::assertSame(2, $pool->workerCount);
    }

    public function testAddBatchRegistersWorkers(): void
    {
        $pool = new Pool();
        $pool->addBatch(4, static function (): void {
        });

        self::assertSame(4, $pool->workerCount);
    }

    public function testOfSizeBuildsBatchPool(): void
    {
        $pool = Pool::ofSize(3, static function (): void {
        });

        self::assertSame(3, $pool->workerCount);
    }

    public function testEventWorkerStartReturnsConstant(): void
    {
        self::assertNotEmpty(Pool::eventWorkerStart());
    }
}
