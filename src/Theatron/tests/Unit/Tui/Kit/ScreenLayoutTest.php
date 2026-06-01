<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Kit;

use Phalanx\Theatron\Tui\Drawing\Rect;
use Phalanx\Theatron\Tui\Kit\ScreenLayout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScreenLayoutTest extends TestCase
{
    #[Test]
    public function slotAddsNamedEntry(): void
    {
        $layout = new ScreenLayout();
        $layout->slot('main', static fn(int $w, int $h): Rect => Rect::of(0, 0, $w, $h));

        self::assertArrayHasKey('main', $layout->slots);
    }

    #[Test]
    public function slotReturnsSelf(): void
    {
        $layout = new ScreenLayout();
        $result = $layout->slot('main', static fn(int $w, int $h): Rect => Rect::of(0, 0, $w, $h));

        self::assertSame($layout, $result);
    }

    #[Test]
    public function regionThrowsForUnknownSlot(): void
    {
        $layout = new ScreenLayout();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown layout slot: missing');

        $layout->region('missing');
    }

    #[Test]
    public function mainWithStatusBarCreatesTwoSlots(): void
    {
        $layout = ScreenLayout::mainWithStatusBar();

        self::assertArrayHasKey('main', $layout->slots);
        self::assertArrayHasKey('status', $layout->slots);
    }

    #[Test]
    public function mainWithStatusBarMainRectLeavesRoomForStatusBar(): void
    {
        $layout = ScreenLayout::mainWithStatusBar();
        $main = $layout->slots['main']->rect(80, 24);
        $status = $layout->slots['status']->rect(80, 24);

        self::assertSame(0, $main->y);
        self::assertSame(80, $main->width);
        self::assertSame(23, $main->height);

        self::assertSame(23, $status->y);
        self::assertSame(80, $status->width);
        self::assertSame(1, $status->height);
    }

    #[Test]
    public function mainWithDevtoolsAndStatusBarCreatesThreeSlots(): void
    {
        $layout = ScreenLayout::mainWithDevtoolsAndStatusBar();

        self::assertArrayHasKey('main', $layout->slots);
        self::assertArrayHasKey('devtools', $layout->slots);
        self::assertArrayHasKey('status', $layout->slots);
    }

    #[Test]
    public function mainWithDevtoolsAndStatusBarDevtoolsHeightIsRespected(): void
    {
        $layout = ScreenLayout::mainWithDevtoolsAndStatusBar(devtoolsHeight: 8);
        $devtools = $layout->slots['devtools']->rect(80, 30);

        self::assertSame(8, $devtools->height);
    }
}
