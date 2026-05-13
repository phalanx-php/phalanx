<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

use PHPUnit\Framework\TestCase;
use Phalanx\Pool\ObjectPool;

final class ReadonlyGhostVerificationTest extends TestCase
{
    public function testReadonlyFirstAcquireSucceeds(): void
    {
        $pool = new ObjectPool(ReadonlyStub::class, 4);

        $obj = $pool->acquire(static function (ReadonlyStub $o): void {
            $o->id = 'leonidas-001';
            $o->code = 300;
            $o->label = 'Thermopylae';
            $o->score = 9.5;
        });

        self::assertSame('leonidas-001', $obj->id);
        self::assertSame(300, $obj->code);
        self::assertSame('Thermopylae', $obj->label);
        self::assertSame(9.5, $obj->score);
    }

    /** resetAsLazyGhost does not clear the readonly write-once flag */
    public function testReadonlyReAcquireFailsAfterRecycle(): void
    {
        $pool = new ObjectPool(ReadonlyStub::class, 4);

        $obj = $pool->acquire(static function (ReadonlyStub $o): void {
            $o->id = 'achilles-001';
            $o->code = 1;
            $o->label = 'Troy';
            $o->score = 10.0;
        });

        $pool->release($obj);

        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/Cannot modify readonly property/');

        $pool->acquire(static function (ReadonlyStub $o): void {
            $o->id = 'odysseus-002';
            $o->code = 2;
        });
    }

    public function testAsymmetricVisibilityWorksWithGhostInitializer(): void
    {
        $pool = new ObjectPool(AsymmetricStub::class, 4);

        $obj = $pool->acquire(static function (AsymmetricStub $o): void {
            $o->id = 'leonidas-001';
            $o->code = 300;
            $o->label = 'Thermopylae';
            $o->score = 9.5;
        });

        self::assertInstanceOf(AsymmetricStub::class, $obj);
        self::assertSame('leonidas-001', $obj->id);
        self::assertSame(300, $obj->code);
        self::assertSame('Thermopylae', $obj->label);
        self::assertSame(9.5, $obj->score);
    }

    public function testAsymmetricPropertiesResetOnReAcquire(): void
    {
        $pool = new ObjectPool(AsymmetricStub::class, 4);

        $obj = $pool->acquire(static function (AsymmetricStub $o): void {
            $o->id = 'achilles-001';
            $o->code = 1;
            $o->label = 'Troy';
            $o->score = 10.0;
        });

        $originalId = spl_object_id($obj);
        $pool->release($obj);

        $recycled = $pool->acquire(static function (AsymmetricStub $o): void {
            $o->id = 'odysseus-002';
            $o->code = 2;
            $o->label = 'Ithaca';
            $o->score = 8.0;
        });

        self::assertSame($originalId, spl_object_id($recycled), 'Same ZMM slot reused');
        self::assertSame('odysseus-002', $recycled->id);
        self::assertSame(2, $recycled->code);
        self::assertSame('Ithaca', $recycled->label);
        self::assertSame(8.0, $recycled->score);
    }

    public function testMultipleRecyclesWithAsymmetricProperties(): void
    {
        $pool = new ObjectPool(AsymmetricStub::class, 2);

        for ($i = 0; $i < 5; $i++) {
            $obj = $pool->acquire(static function (AsymmetricStub $o) use ($i): void {
                $o->id = "hoplite-{$i}";
                $o->code = $i * 100;
                $o->label = "phalanx-{$i}";
                $o->score = (float) $i;
            });

            self::assertSame("hoplite-{$i}", $obj->id);
            self::assertSame($i * 100, $obj->code);

            $pool->release($obj);
        }

        self::assertSame(4, $pool->stats()['hits']);
        self::assertSame(1, $pool->stats()['misses']);
    }
}
