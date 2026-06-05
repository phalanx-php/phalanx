<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Styles;

use Phalanx\Tui\Tui\Drawing\Rect;
use Phalanx\Tui\Tui\Styles\Size;
use Phalanx\Tui\Tui\Styles\SizeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SizeResolverTest extends TestCase
{
    #[Test]
    public function fixedSizesAllocateExactly(): void
    {
        $result = SizeResolver::resolve(100, [Size::fixed(30), Size::fixed(70)]);

        self::assertSame([30, 70], $result);
    }

    #[Test]
    public function fillDistributesRemainingSpace(): void
    {
        $result = SizeResolver::resolve(100, [Size::fixed(20), Size::fill(), Size::fixed(10)]);

        self::assertSame([20, 70, 10], $result);
    }

    #[Test]
    public function percentAllocatesProportionally(): void
    {
        $result = SizeResolver::resolve(200, [Size::percent(25), Size::percent(75)]);

        self::assertSame([50, 150], $result);
    }

    #[Test]
    public function verticalProducesCorrectRects(): void
    {
        $area = Rect::sized(80, 24);
        $rects = SizeResolver::vertical($area, [Size::fixed(3), Size::fill(), Size::fixed(1)]);

        self::assertCount(3, $rects);
        self::assertSame(0, $rects[0]->y);
        self::assertSame(3, $rects[0]->height);
        self::assertSame(3, $rects[1]->y);
        self::assertSame(20, $rects[1]->height);
        self::assertSame(23, $rects[2]->y);
        self::assertSame(1, $rects[2]->height);
    }

    #[Test]
    public function horizontalProducesCorrectRects(): void
    {
        $area = Rect::sized(80, 24);
        $rects = SizeResolver::horizontal($area, [Size::fixed(10), Size::fill(), Size::fixed(10)]);

        self::assertCount(3, $rects);
        self::assertSame(0, $rects[0]->x);
        self::assertSame(10, $rects[0]->width);
        self::assertSame(10, $rects[1]->x);
        self::assertSame(60, $rects[1]->width);
        self::assertSame(70, $rects[2]->x);
        self::assertSame(10, $rects[2]->width);
    }

    #[Test]
    public function betweenExpandsToFillAvailableSpace(): void
    {
        $result = SizeResolver::resolve(100, [Size::fixed(20), Size::between(10, 60)]);

        self::assertSame([20, 60], $result);
    }

    #[Test]
    public function betweenClampsAtMax(): void
    {
        $result = SizeResolver::resolve(200, [Size::between(10, 50)]);

        self::assertSame([50], $result);
    }

    #[Test]
    public function betweenUsesMinWhenConstrained(): void
    {
        $result = SizeResolver::resolve(30, [Size::fixed(25), Size::between(10, 50)]);

        self::assertSame([25, 5], $result);
    }

    #[Test]
    public function fractionalDistributesProportionally(): void
    {
        $result = SizeResolver::resolve(100, [Size::fr(1), Size::fr(3)]);

        self::assertSame([25, 75], $result);
    }

    #[Test]
    public function fractionalWithFixed(): void
    {
        $result = SizeResolver::resolve(100, [Size::fixed(20), Size::fr(1), Size::fr(1)]);

        self::assertSame([20, 40, 40], $result);
    }

    #[Test]
    public function emptyInputReturnsEmpty(): void
    {
        self::assertSame([], SizeResolver::resolve(100, []));
    }
}
