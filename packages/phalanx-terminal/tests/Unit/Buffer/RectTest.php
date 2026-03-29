<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Tests\Unit\Buffer;

use Phalanx\Terminal\Buffer\Rect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RectTest extends TestCase
{
    #[Test]
    public function computed_properties(): void
    {
        $rect = Rect::of(5, 10, 20, 15);

        self::assertSame(25, $rect->right);
        self::assertSame(25, $rect->bottom);
        self::assertSame(300, $rect->area);
    }

    #[Test]
    public function contains_checks_bounds(): void
    {
        $rect = Rect::of(2, 3, 5, 4);

        self::assertTrue($rect->contains(2, 3));
        self::assertTrue($rect->contains(6, 6));
        self::assertFalse($rect->contains(7, 3));
        self::assertFalse($rect->contains(2, 7));
        self::assertFalse($rect->contains(1, 3));
    }

    #[Test]
    public function intersect_computes_overlap(): void
    {
        $a = Rect::of(0, 0, 10, 10);
        $b = Rect::of(5, 5, 10, 10);

        $inter = $a->intersect($b);

        self::assertSame(5, $inter->x);
        self::assertSame(5, $inter->y);
        self::assertSame(5, $inter->width);
        self::assertSame(5, $inter->height);
    }

    #[Test]
    public function intersect_with_no_overlap_returns_zero_area(): void
    {
        $a = Rect::of(0, 0, 5, 5);
        $b = Rect::of(10, 10, 5, 5);

        $inter = $a->intersect($b);

        self::assertSame(0, $inter->area);
    }

    #[Test]
    public function sized_creates_at_origin(): void
    {
        $rect = Rect::sized(80, 24);

        self::assertSame(0, $rect->x);
        self::assertSame(0, $rect->y);
        self::assertSame(80, $rect->width);
        self::assertSame(24, $rect->height);
    }
}
