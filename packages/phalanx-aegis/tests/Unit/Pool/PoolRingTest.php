<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

use Phalanx\Pool\PoolRing;
use PHPUnit\Framework\TestCase;

final class PoolRingTest extends TestCase
{
    public function testNextReturnsInitializedSlot(): void
    {
        $ring = new PoolRing(PoolableStub::class, 4);

        $obj = $ring->next(static function (PoolableStub $o): void {
            $o->name = 'Leonidas';
            $o->value = 300;
        });

        self::assertInstanceOf(PoolableStub::class, $obj);
        self::assertSame('Leonidas', $obj->name);
        self::assertSame(300, $obj->value);
    }

    public function testRingWrapsAfterSizeAdvances(): void
    {
        $ring = new PoolRing(PoolableStub::class, 3);
        $ids = [];

        for ($i = 0; $i < 3; $i++) {
            $obj = $ring->next(static function (PoolableStub $o) use ($i): void {
                $o->name = "slot-$i";
                $o->value = $i;
            });
            $ids[] = spl_object_id($obj);
        }

        self::assertCount(3, array_unique($ids));

        $wrapped = $ring->next(static function (PoolableStub $o): void {
            $o->name = 'wrapped';
            $o->value = 99;
        });

        self::assertSame($ids[0], spl_object_id($wrapped));
        self::assertSame('wrapped', $wrapped->name);
    }

    public function testSlotReusedAtPositionPlusSize(): void
    {
        $ring = new PoolRing(PoolableStub::class, 4);
        $firstRound = [];

        for ($i = 0; $i < 4; $i++) {
            $obj = $ring->next(static function (PoolableStub $o) use ($i): void {
                $o->name = "r1-$i";
                $o->value = $i;
            });
            $firstRound[] = spl_object_id($obj);
        }

        for ($i = 0; $i < 4; $i++) {
            $obj = $ring->next(static function (PoolableStub $o) use ($i): void {
                $o->name = "r2-$i";
                $o->value = $i + 100;
            });
            self::assertSame($firstRound[$i], spl_object_id($obj));
            self::assertSame("r2-$i", $obj->name);
            self::assertSame($i + 100, $obj->value);
        }
    }

    public function testResetReturnsCursorToStart(): void
    {
        $ring = new PoolRing(PoolableStub::class, 3);

        $first = $ring->next(static function (PoolableStub $o): void {
            $o->name = 'before';
            $o->value = 1;
        });
        $firstId = spl_object_id($first);

        $ring->next(static function (PoolableStub $o): void {
            $o->name = 'second';
            $o->value = 2;
        });

        $ring->reset();

        $afterReset = $ring->next(static function (PoolableStub $o): void {
            $o->name = 'after';
            $o->value = 3;
        });

        self::assertSame($firstId, spl_object_id($afterReset));
        self::assertSame('after', $afterReset->name);
    }

    public function testInitializerSetsPropertiesOnReusedSlot(): void
    {
        $ring = new PoolRing(MutableStub::class, 2);

        $obj = $ring->next(static function (MutableStub $o): void {
            $o->label = 'Sparta';
        });
        self::assertSame('Sparta', $obj->label);

        $ring->next(static function (MutableStub $o): void {
            $o->label = 'Athens';
        });

        $reused = $ring->next(static function (MutableStub $o): void {
            $o->label = 'Corinth';
        });

        self::assertSame(spl_object_id($obj), spl_object_id($reused));
        self::assertSame('Corinth', $reused->label);
    }

    public function testRingSizeOneWrapsImmediately(): void
    {
        $ring = new PoolRing(PoolableStub::class, 1);

        $first = $ring->next(static function (PoolableStub $o): void {
            $o->name = 'Achilles';
            $o->value = 1;
        });
        $firstId = spl_object_id($first);

        $second = $ring->next(static function (PoolableStub $o): void {
            $o->name = 'Odysseus';
            $o->value = 2;
        });

        self::assertSame($firstId, spl_object_id($second));
        self::assertSame('Odysseus', $second->name);
    }

    public function testResetOnFreshRingDoesNotThrow(): void
    {
        $ring = new PoolRing(PoolableStub::class, 4);

        $ring->reset();

        $obj = $ring->next(static function (PoolableStub $o): void {
            $o->name = 'Themistocles';
            $o->value = 480;
        });

        self::assertSame('Themistocles', $obj->name);
    }
}
