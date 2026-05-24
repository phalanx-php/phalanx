<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Rendering;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Rendering\Compositor;
use Phalanx\Theatron\Rendering\Region;
use Phalanx\Theatron\Rendering\RegionConfig;
use Phalanx\Theatron\Style\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompositorTest extends TestCase
{
    #[Test]
    public function registerAndGetRegion(): void
    {
        $compositor = new Compositor();
        $region = new Region('main', Rect::of(0, 0, 80, 24));

        $compositor->register($region);

        self::assertSame($region, $compositor->get('main'));
    }

    #[Test]
    public function removeRegion(): void
    {
        $compositor = new Compositor();
        $region = new Region('main', Rect::of(0, 0, 80, 24));

        $compositor->register($region);
        $compositor->remove('main');

        self::assertNull($compositor->get('main'));
    }

    #[Test]
    public function isDirtyWhenAnyRegionIsDirty(): void
    {
        $compositor = new Compositor();
        $region = new Region('main', Rect::of(0, 0, 10, 5));

        $compositor->register($region);

        self::assertTrue($compositor->isDirty);
    }

    #[Test]
    public function isDirtyFalseWhenNoRegions(): void
    {
        $compositor = new Compositor();

        self::assertFalse($compositor->isDirty);
    }

    #[Test]
    public function composeAllBlitsInZOrder(): void
    {
        $compositor = new Compositor();

        $background = new Region(
            'bg',
            Rect::of(0, 0, 5, 1),
            new RegionConfig(zIndex: 0),
        );
        $overlay = new Region(
            'overlay',
            Rect::of(0, 0, 5, 1),
            new RegionConfig(zIndex: 1),
        );

        $background->buffer()->set(0, 0, 'A', Style::new());
        $overlay->buffer()->set(0, 0, 'B', Style::new());
        $overlay->buffer()->set(1, 0, ' ', Style::new());

        $background->markDirty();
        $overlay->markDirty();

        $compositor->register($overlay);
        $compositor->register($background);

        $target = Buffer::empty(5, 1);
        $compositor->composeAll($target);

        $cells = $target->cells();
        self::assertSame('B', $cells[0]->char);
    }

    #[Test]
    public function markDirtyAllowsCompose(): void
    {
        $compositor = new Compositor();
        $region = new Region('r', Rect::of(0, 0, 5, 1));

        $compositor->register($region);

        $target = Buffer::empty(5, 1);
        $compositor->composeAll($target);

        self::assertFalse($region->isDirty);

        $region->markDirty();
        self::assertTrue($region->isDirty);
    }

    #[Test]
    public function registerInvalidatesZOrderCache(): void
    {
        $compositor = new Compositor();
        $r1 = new Region('r1', Rect::of(0, 0, 5, 1), new RegionConfig(zIndex: 5));
        $r2 = new Region('r2', Rect::of(0, 0, 5, 1), new RegionConfig(zIndex: 0));

        $compositor->register($r1);
        $compositor->register($r2);

        $target = Buffer::empty(5, 1);
        $compositor->composeAll($target);

        $r3 = new Region('r3', Rect::of(0, 0, 5, 1), new RegionConfig(zIndex: 10));
        $compositor->register($r3);

        self::assertSame($r3, $compositor->get('r3'));
    }
}
