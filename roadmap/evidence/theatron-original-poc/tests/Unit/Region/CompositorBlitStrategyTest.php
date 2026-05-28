<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Region;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Region\Compositor;
use Phalanx\Theatron\Region\Region;
use Phalanx\Theatron\Region\RegionConfig;
use Phalanx\Theatron\Style\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompositorBlitStrategyTest extends TestCase
{
    #[Test]
    public function compose_uses_blit_full_for_z_zero(): void
    {
        // z=0 region with an unwritten (transparent) buffer still overwrites the target
        // because blitFull copies every cell regardless of transparency.
        $style = Style::new();

        $region = new Region('base', Rect::sized(1, 1), new RegionConfig());
        // Region buffer is empty (transparent ' ' cells); blitFull still copies them.

        $compositor = new Compositor();
        $compositor->register($region);

        $target = Buffer::filled(1, 1, 'T', $style);
        $compositor->composeAll($target);

        // blitFull overwrote 'T' with the region's default space cell
        self::assertSame(' ', $target->get(0, 0)->char);
    }

    #[Test]
    public function compose_uses_blit_opaque_for_positive_z(): void
    {
        // z=1 region with a transparent buffer must NOT overwrite the target.
        $style = Style::new();

        $region = new Region('overlay', Rect::sized(1, 1), (new RegionConfig())->withZIndex(1));
        // Region buffer stays empty (all transparent).

        $compositor = new Compositor();
        $compositor->register($region);

        $target = Buffer::filled(1, 1, 'T', $style);
        $compositor->composeAll($target);

        self::assertSame('T', $target->get(0, 0)->char);
    }

    #[Test]
    public function z_order_determines_paint_sequence(): void
    {
        $style = Style::new();

        // z=0 paints 'B'; z=1 paints 'A' only on its opaque cells.
        // The compositor sorts ascending, so z=0 goes first, z=1 on top.
        $base = new Region('base', Rect::sized(1, 1), new RegionConfig());
        $base->buffer()->set(0, 0, 'B', $style);
        $base->invalidate();

        $overlay = new Region('overlay', Rect::sized(1, 1), (new RegionConfig())->withZIndex(1));
        $overlay->buffer()->set(0, 0, 'A', $style);
        $overlay->invalidate();

        $compositor = new Compositor();
        $compositor->register($base);
        $compositor->register($overlay);

        $target = Buffer::empty(1, 1);
        $compositor->composeAll($target);

        self::assertSame('A', $target->get(0, 0)->char);
    }
}
