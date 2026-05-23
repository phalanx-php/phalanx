<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

use Phalanx\Pool\PoolRing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PoolRingTest extends TestCase
{
    public function testWithBorrowedPassesInitializedSlotToCallback(): void
    {
        $ring = new PoolRing(PoolableStub::class, 4);

        $state = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'Leonidas';
                $o->value = 300;
            },
            static fn(PoolableStub $o): array => [$o::class, $o->name, $o->value],
        );

        self::assertSame([PoolableStub::class, 'Leonidas', 300], $state);
    }

    public function testRingWrapsAfterSizeAdvances(): void
    {
        $ring = new PoolRing(PoolableStub::class, 3);
        $ids = [];

        for ($i = 0; $i < 3; $i++) {
            $ids[] = $ring->withBorrowed(
                static function (PoolableStub $o) use ($i): void {
                    $o->name = "slot-$i";
                    $o->value = $i;
                },
                static fn(PoolableStub $o): int => spl_object_id($o),
            );
        }

        self::assertCount(3, array_unique($ids));

        $wrapped = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'wrapped';
                $o->value = 99;
            },
            static fn(PoolableStub $o): array => [spl_object_id($o), $o->name],
        );

        self::assertSame([$ids[0], 'wrapped'], $wrapped);
    }

    public function testSlotReusedAtPositionPlusSize(): void
    {
        $ring = new PoolRing(PoolableStub::class, 4);
        $firstRound = [];

        for ($i = 0; $i < 4; $i++) {
            $firstRound[] = $ring->withBorrowed(
                static function (PoolableStub $o) use ($i): void {
                    $o->name = "r1-$i";
                    $o->value = $i;
                },
                static fn(PoolableStub $o): int => spl_object_id($o),
            );
        }

        for ($i = 0; $i < 4; $i++) {
            $state = $ring->withBorrowed(
                static function (PoolableStub $o) use ($i): void {
                    $o->name = "r2-$i";
                    $o->value = $i + 100;
                },
                static fn(PoolableStub $o): array => [spl_object_id($o), $o->name, $o->value],
            );
            self::assertSame([$firstRound[$i], "r2-$i", $i + 100], $state);
        }
    }

    public function testResetReturnsCursorToStart(): void
    {
        $ring = new PoolRing(PoolableStub::class, 3);

        $firstId = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'before';
                $o->value = 1;
            },
            static fn(PoolableStub $o): int => spl_object_id($o),
        );

        $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'second';
                $o->value = 2;
            },
            static fn(): null => null,
        );

        $ring->reset();

        $afterReset = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'after';
                $o->value = 3;
            },
            static fn(PoolableStub $o): array => [spl_object_id($o), $o->name],
        );

        self::assertSame([$firstId, 'after'], $afterReset);
    }

    public function testInitializerSetsPropertiesOnReusedSlot(): void
    {
        $ring = new PoolRing(MutableStub::class, 2);

        $first = $ring->withBorrowed(
            static function (MutableStub $o): void {
                $o->label = 'Sparta';
            },
            static fn(MutableStub $o): array => [spl_object_id($o), $o->label],
        );
        self::assertSame('Sparta', $first[1]);

        $ring->withBorrowed(
            static function (MutableStub $o): void {
                $o->label = 'Athens';
            },
            static fn(): null => null,
        );

        $reused = $ring->withBorrowed(
            static function (MutableStub $o): void {
                $o->label = 'Corinth';
            },
            static fn(MutableStub $o): array => [spl_object_id($o), $o->label],
        );

        self::assertSame([$first[0], 'Corinth'], $reused);
    }

    public function testRingSizeOneWrapsImmediately(): void
    {
        $ring = new PoolRing(PoolableStub::class, 1);

        $firstId = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'Achilles';
                $o->value = 1;
            },
            static fn(PoolableStub $o): int => spl_object_id($o),
        );

        $second = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'Odysseus';
                $o->value = 2;
            },
            static fn(PoolableStub $o): array => [spl_object_id($o), $o->name],
        );

        self::assertSame([$firstId, 'Odysseus'], $second);
    }

    public function testResetOnFreshRingDoesNotThrow(): void
    {
        $ring = new PoolRing(PoolableStub::class, 4);

        $ring->reset();

        $name = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'Themistocles';
                $o->value = 480;
            },
            static fn(PoolableStub $o): string => $o->name,
        );

        self::assertSame('Themistocles', $name);
    }

    public function testConstructorRejectsUnmarkedPoolValues(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PoolRing classes must implement BorrowedValue');

        new PoolRing(\stdClass::class, 1);
    }

    public function testBorrowReleasesSlotWhenCallbackThrows(): void
    {
        $ring = new PoolRing(PoolableStub::class, 1);

        try {
            $ring->withBorrowed(
                static function (PoolableStub $o): void {
                    $o->name = 'before';
                    $o->value = 1;
                },
                static function (): never {
                    throw new RuntimeException('borrow failed');
                },
            );
        } catch (RuntimeException $e) {
            self::assertSame('borrow failed', $e->getMessage());
        }

        $name = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'after';
                $o->value = 2;
            },
            static fn(PoolableStub $o): string => $o->name,
        );

        self::assertSame('after', $name);
    }

    public function testInitializerFailureReleasesSlot(): void
    {
        $ring = new PoolRing(PoolableStub::class, 1);

        try {
            $ring->withBorrowed(
                static function (): void {
                    throw new RuntimeException('initializer failed');
                },
                static fn(): null => null,
            );
        } catch (RuntimeException $e) {
            self::assertSame('initializer failed', $e->getMessage());
        }

        $name = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'after initializer';
                $o->value = 2;
            },
            static fn(PoolableStub $o): string => $o->name,
        );

        self::assertSame('after initializer', $name);
    }

    public function testBorrowCallbackCannotReturnBorrowedSlot(): void
    {
        $ring = new PoolRing(PoolableStub::class, 1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Borrow callbacks must return owned values');

        $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'escaped';
                $o->value = 1;
            },
            static fn(PoolableStub $o): PoolableStub => $o,
        );
    }

    public function testBorrowCallbackCannotReturnClosureCapturingBorrowedSlot(): void
    {
        $ring = new PoolRing(PoolableStub::class, 1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Borrow callbacks must return owned values');

        $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'captured';
                $o->value = 1;
            },
            static fn(PoolableStub $o): \Closure => static fn(): string => $o->name,
        );
    }

    public function testNestedBorrowSkipsCheckedOutSlot(): void
    {
        $ring = new PoolRing(PoolableStub::class, 2);

        $ids = $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'outer';
                $o->value = 1;
            },
            static fn(PoolableStub $outer): array => [
                spl_object_id($outer),
                $ring->withBorrowed(
                    static function (PoolableStub $inner): void {
                        $inner->name = 'inner';
                        $inner->value = 2;
                    },
                    static fn(PoolableStub $inner): int => spl_object_id($inner),
                ),
            ],
        );

        self::assertNotSame($ids[0], $ids[1]);
    }

    public function testResetRejectsCheckedOutSlots(): void
    {
        $ring = new PoolRing(PoolableStub::class, 1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot reset PoolRing while values are borrowed');

        $ring->withBorrowed(
            static function (PoolableStub $o): void {
                $o->name = 'borrowed';
                $o->value = 1;
            },
            static function () use ($ring): void {
                $ring->reset();
            },
        );
    }
}
