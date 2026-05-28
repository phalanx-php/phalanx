<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Kit;

use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Kit\ScreenLayout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenLayoutTest extends TestCase
{
    #[Test]
    public function slot_adds_named_slot(): void
    {
        $layout = (new ScreenLayout())
            ->slot('main', static fn(int $w, int $h): Rect => Rect::of(0, 0, $w, $h));

        self::assertArrayHasKey('main', $layout->slots);
        self::assertSame('main', $layout->slots['main']->name);
    }

    #[Test]
    public function main_with_status_bar_creates_two_slots(): void
    {
        $layout = ScreenLayout::mainWithStatusBar();

        self::assertArrayHasKey('main', $layout->slots);
        self::assertArrayHasKey('status', $layout->slots);

        $mainRect = $layout->slots['main']->rect(80, 24);
        $statusRect = $layout->slots['status']->rect(80, 24);

        self::assertSame(0, $mainRect->y);
        self::assertSame(23, $mainRect->height);
        self::assertSame(23, $statusRect->y);
        self::assertSame(1, $statusRect->height);
    }

    #[Test]
    public function main_with_devtools_and_status_bar_creates_three_slots(): void
    {
        $layout = ScreenLayout::mainWithDevtoolsAndStatusBar(6);

        self::assertArrayHasKey('main', $layout->slots);
        self::assertArrayHasKey('devtools', $layout->slots);
        self::assertArrayHasKey('status', $layout->slots);
    }

    #[Test]
    public function region_throws_for_unknown_slot(): void
    {
        $layout = ScreenLayout::mainWithStatusBar();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown layout slot');

        $layout->region('nonexistent');
    }

    #[Test]
    public function region_throws_before_attach(): void
    {
        $layout = ScreenLayout::mainWithStatusBar();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not been attached');

        $layout->region('main');
    }
}
