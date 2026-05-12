<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

use Phalanx\Pool\ObjectPool;
use PHPUnit\Framework\TestCase;

final class ObjectPoolTest extends TestCase
{
    public function testAcquireReturnsCorrectClass(): void
    {
        $pool = new ObjectPool(PoolableStub::class, 8);

        $obj = $pool->acquire(static function (PoolableStub $o): void {
            $o->name = 'Apollo';
            $o->value = 42;
        });

        self::assertInstanceOf(PoolableStub::class, $obj);
        self::assertSame('Apollo', $obj->name);
        self::assertSame(42, $obj->value);
    }

    public function testReleaseAndAcquireRecyclesObject(): void
    {
        $pool = new ObjectPool(PoolableStub::class, 8);

        $first = $pool->acquire(static function (PoolableStub $o): void {
            $o->name = 'Ares';
            $o->value = 1;
        });
        $firstId = spl_object_id($first);

        $pool->release($first);

        $second = $pool->acquire(static function (PoolableStub $o): void {
            $o->name = 'Poseidon';
            $o->value = 2;
        });

        self::assertSame($firstId, spl_object_id($second));
        self::assertSame('Poseidon', $second->name);
        self::assertSame(2, $second->value);
    }

    public function testOverflowDiscardsGracefully(): void
    {
        $pool = new ObjectPool(PoolableStub::class, 2);

        $a = $pool->acquire(static function (PoolableStub $o): void {
            $o->name = 'a';
            $o->value = 1;
        });
        $b = $pool->acquire(static function (PoolableStub $o): void {
            $o->name = 'b';
            $o->value = 2;
        });
        $c = $pool->acquire(static function (PoolableStub $o): void {
            $o->name = 'c';
            $o->value = 3;
        });

        $pool->release($a);
        $pool->release($b);
        $pool->release($c);

        $stats = $pool->stats();
        self::assertSame(0, $stats['hits']);
        self::assertSame(3, $stats['misses']);
        self::assertSame(1, $stats['overflows']);
        self::assertSame(2, $stats['free']);
    }

    public function testStatsTrackHitsAndMisses(): void
    {
        $pool = new ObjectPool(PoolableStub::class, 4);

        $init = static function (PoolableStub $o): void {
            $o->name = 'x';
            $o->value = 0;
        };

        $obj = $pool->acquire($init);
        self::assertSame(1, $pool->stats()['misses']);
        self::assertSame(0, $pool->stats()['hits']);

        $pool->release($obj);

        $pool->acquire($init);
        self::assertSame(1, $pool->stats()['misses']);
        self::assertSame(1, $pool->stats()['hits']);
    }

    public function testWorksWithFinalClassMutableFields(): void
    {
        $pool = new ObjectPool(MutableStub::class, 4);

        $obj = $pool->acquire(static function (MutableStub $o): void {
            $o->label = 'Thermopylae';
        });

        self::assertSame('Thermopylae', $obj->label);

        $pool->release($obj);

        $recycled = $pool->acquire(static function (MutableStub $o): void {
            $o->label = 'Marathon';
        });

        self::assertSame(spl_object_id($obj), spl_object_id($recycled));
        self::assertSame('Marathon', $recycled->label);
    }

    public function testStatsReportsCapacity(): void
    {
        $pool = new ObjectPool(PoolableStub::class, 16);

        self::assertSame(16, $pool->stats()['capacity']);
    }

    public function testDoubleReleaseRespectsCapacity(): void
    {
        $pool = new ObjectPool(PoolableStub::class, 1);

        $init = static function (PoolableStub $o): void {
            $o->name = 'Pericles';
            $o->value = 1;
        };

        $obj = $pool->acquire($init);
        $pool->release($obj);
        $pool->release($obj);

        self::assertSame(1, $pool->stats()['free']);
        self::assertSame(1, $pool->stats()['overflows']);
    }
}
