<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Kit;

use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Kit\ScreenLayoutSlot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenLayoutSlotTest extends TestCase
{
    #[Test]
    public function rect_delegates_to_factory(): void
    {
        $slot = new ScreenLayoutSlot(
            'main',
            static fn(int $w, int $h): Rect => Rect::of(0, 0, $w, $h - 1),
        );

        $rect = $slot->rect(80, 24);

        self::assertSame(0, $rect->x);
        self::assertSame(0, $rect->y);
        self::assertSame(80, $rect->width);
        self::assertSame(23, $rect->height);
    }

    #[Test]
    public function region_throws_before_attach(): void
    {
        $slot = new ScreenLayoutSlot(
            'status',
            static fn(int $w, int $h): Rect => Rect::of(0, $h - 1, $w, 1),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not been attached');

        $slot->region();
    }

    #[Test]
    public function name_is_accessible(): void
    {
        $slot = new ScreenLayoutSlot(
            'devtools',
            static fn(int $w, int $h): Rect => Rect::of(0, 0, $w, $h),
        );

        self::assertSame('devtools', $slot->name);
    }
}
