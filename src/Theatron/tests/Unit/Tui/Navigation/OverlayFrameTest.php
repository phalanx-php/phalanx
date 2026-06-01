<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Navigation;

use Phalanx\Theatron\Tui\Drawing\Rect;
use Phalanx\Theatron\Tui\Navigation\OverlayFrame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OverlayFrameTest extends TestCase
{
    #[Test]
    public function rightPanelUsesReadableWidth(): void
    {
        $frame = OverlayFrame::rightPanel(Rect::sized(120, 30));

        self::assertSame(48, $frame->rect->width);
        self::assertSame(72, $frame->rect->x);
        self::assertSame(30, $frame->rect->height);
        self::assertTrue($frame->backdrop);
    }

    #[Test]
    public function rightPanelClampsToNarrowBounds(): void
    {
        $frame = OverlayFrame::rightPanel(Rect::sized(20, 10));

        self::assertSame(20, $frame->rect->width);
        self::assertSame(0, $frame->rect->x);
        self::assertSame(10, $frame->rect->height);
    }
}
