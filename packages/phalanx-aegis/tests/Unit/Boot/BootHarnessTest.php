<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Boot;

use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Required;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class BootHarnessTest extends PhalanxTestCase
{
    #[Test]
    public function ofReturnsAllRequirementsInOrder(): void
    {
        $a = Required::env('A');
        $b = Required::env('B');
        $harness = BootHarness::of($a, $b);

        self::assertCount(2, $harness->all());
        self::assertSame($a, $harness->all()[0]);
        self::assertSame($b, $harness->all()[1]);
    }

    #[Test]
    public function noneIsEmpty(): void
    {
        self::assertTrue(BootHarness::none()->isEmpty());
    }

    #[Test]
    public function ofWithRequirementsIsNotEmpty(): void
    {
        self::assertFalse(BootHarness::of(Required::env('A'))->isEmpty());
    }

    #[Test]
    public function allReturnsEmptyArrayForNone(): void
    {
        self::assertSame([], BootHarness::none()->all());
    }

    #[Test]
    public function mergeAggregatesRequirements(): void
    {
        $a = BootHarness::of(Required::env('A'));
        $b = BootHarness::of(Required::env('B'), Required::env('C'));
        $merged = $a->merge($b);

        self::assertCount(3, $merged->all());
    }

    #[Test]
    public function mergeWithEmptyOtherReturnsSelf(): void
    {
        $harness = BootHarness::of(Required::env('A'));
        $merged = $harness->merge(BootHarness::none());

        self::assertSame($harness, $merged);
    }

    #[Test]
    public function mergeWithSelfEmptyReturnsOther(): void
    {
        $other = BootHarness::of(Required::env('A'));
        $merged = BootHarness::none()->merge($other);

        self::assertSame($other, $merged);
    }

    #[Test]
    public function mergePreservesOrder(): void
    {
        $first = Required::env('FIRST');
        $second = Required::env('SECOND');
        $third = Required::env('THIRD');

        $merged = BootHarness::of($first, $second)->merge(BootHarness::of($third));

        $all = $merged->all();
        self::assertSame($first, $all[0]);
        self::assertSame($second, $all[1]);
        self::assertSame($third, $all[2]);
    }
}
