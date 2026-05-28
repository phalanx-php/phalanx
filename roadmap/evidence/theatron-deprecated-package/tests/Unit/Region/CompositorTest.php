<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Region;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Region\Compositor;
use Phalanx\Theatron\Region\Region;
use Phalanx\Theatron\Region\RegionConfig;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompositorTest extends TestCase
{
    #[Test]
    public function compose_blits_dirty_regions(): void
    {
        $region = new Region('header', Rect::of(0, 0, 10, 1));
        $region->draw(new class implements Widget {
            public function render(Rect $area, Buffer $buffer): void
            {
                $buffer->putString(0, 0, 'HEADER', Style::new()->bold());
            }
        });

        $compositor = new Compositor();
        $compositor->register($region);

        $target = Buffer::empty(10, 5);
        $compositor->compose($target, 0.0);

        self::assertSame('H', $target->get(0, 0)->char);
        self::assertSame('E', $target->get(1, 0)->char);
        self::assertSame('A', $target->get(2, 0)->char);
        self::assertSame('D', $target->get(3, 0)->char);
        self::assertSame('E', $target->get(4, 0)->char);
        self::assertSame('R', $target->get(5, 0)->char);
    }

    #[Test]
    public function compose_skips_clean_regions(): void
    {
        $region = new Region('test', Rect::of(0, 0, 5, 1));
        $region->draw(new class implements Widget {
            public function render(Rect $area, Buffer $buffer): void
            {
                $buffer->putString(0, 0, 'ABC', Style::new());
            }
        });

        $compositor = new Compositor();
        $compositor->register($region);

        $target = Buffer::empty(5, 1);
        $compositor->compose($target, 0.0);

        self::assertSame('A', $target->get(0, 0)->char);
        self::assertFalse($region->isDirty);

        $target2 = Buffer::empty(5, 1);
        $compositor->compose($target2, 0.1);

        self::assertSame(' ', $target2->get(0, 0)->char);
    }

    #[Test]
    public function z_order_higher_paints_last(): void
    {
        $low = new Region('low', Rect::of(0, 0, 5, 1), new RegionConfig(zIndex: 0));
        $low->draw(new class implements Widget {
            public function render(Rect $area, Buffer $buffer): void
            {
                $buffer->putString(0, 0, 'AAAAA', Style::new());
            }
        });

        $high = new Region('high', Rect::of(2, 0, 3, 1), new RegionConfig(zIndex: 10));
        $high->draw(new class implements Widget {
            public function render(Rect $area, Buffer $buffer): void
            {
                $buffer->putString(0, 0, 'BBB', Style::new());
            }
        });

        $compositor = new Compositor();
        $compositor->register($low);
        $compositor->register($high);

        $target = Buffer::empty(5, 1);
        $compositor->compose($target, 0.0);

        self::assertSame('A', $target->get(0, 0)->char);
        self::assertSame('A', $target->get(1, 0)->char);
        self::assertSame('B', $target->get(2, 0)->char);
        self::assertSame('B', $target->get(3, 0)->char);
        self::assertSame('B', $target->get(4, 0)->char);
    }

    #[Test]
    public function is_dirty_reflects_region_state(): void
    {
        $compositor = new Compositor();

        self::assertFalse($compositor->isDirty);

        $region = new Region('test', Rect::of(0, 0, 5, 1));
        $compositor->register($region);

        self::assertTrue($compositor->isDirty);
    }

    #[Test]
    public function remove_drops_region(): void
    {
        $compositor = new Compositor();
        $compositor->register(new Region('a', Rect::of(0, 0, 5, 1)));

        $compositor->remove('a');

        self::assertNull($compositor->get('a'));
        self::assertFalse($compositor->isDirty);
    }

    #[Test]
    public function tick_rate_throttles_rendering(): void
    {
        $region = new Region('slow', Rect::of(0, 0, 5, 1), new RegionConfig(tickRate: 10.0));
        $region->draw(new class implements Widget {
            public function render(Rect $area, Buffer $buffer): void
            {
                $buffer->putString(0, 0, 'X', Style::new());
            }
        });

        $compositor = new Compositor();
        $compositor->register($region);

        $target = Buffer::empty(5, 1);
        $compositor->compose($target, 0.0);
        self::assertSame('X', $target->get(0, 0)->char);

        $region->invalidate();
        $region->draw(new class implements Widget {
            public function render(Rect $area, Buffer $buffer): void
            {
                $buffer->putString(0, 0, 'Y', Style::new());
            }
        });

        $target2 = Buffer::empty(5, 1);
        $compositor->compose($target2, 0.05);
        self::assertSame(' ', $target2->get(0, 0)->char);

        $target3 = Buffer::empty(5, 1);
        $compositor->compose($target3, 0.11);
        self::assertSame('Y', $target3->get(0, 0)->char);
    }
}
