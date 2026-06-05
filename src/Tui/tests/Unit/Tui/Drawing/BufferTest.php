<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Drawing;

use Phalanx\Tui\Tui\Drawing\Buffer;
use Phalanx\Tui\Tui\Drawing\Rect;
use Phalanx\Tui\Tui\Styles\Color;
use Phalanx\Tui\Tui\Styles\Modifier;
use Phalanx\Tui\Tui\Styles\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BufferTest extends TestCase
{
    #[Test]
    public function putStringWritesAscii(): void
    {
        $buf = Buffer::empty(10, 1);
        $buf->putString(0, 0, 'abc', Style::new());

        self::assertSame('a', $buf->get(0, 0)->char);
        self::assertSame('b', $buf->get(1, 0)->char);
        self::assertSame('c', $buf->get(2, 0)->char);
        self::assertSame(' ', $buf->get(3, 0)->char);
    }

    #[Test]
    public function putStringHandlesMultiByte(): void
    {
        $buf = Buffer::empty(10, 1);
        $endX = $buf->putString(0, 0, 'café', Style::new());

        self::assertSame('c', $buf->get(0, 0)->char);
        self::assertSame('a', $buf->get(1, 0)->char);
        self::assertSame('f', $buf->get(2, 0)->char);
        self::assertSame('é', $buf->get(3, 0)->char);
        self::assertSame(4, $endX);
    }

    #[Test]
    public function putStringHandlesWideCharacters(): void
    {
        $buf = Buffer::empty(10, 1);
        $endX = $buf->putString(0, 0, '漢字', Style::new());

        self::assertSame('漢', $buf->get(0, 0)->char);
        self::assertSame('', $buf->get(1, 0)->char);
        self::assertTrue($buf->get(1, 0)->skipDiff);
        self::assertSame('字', $buf->get(2, 0)->char);
        self::assertSame('', $buf->get(3, 0)->char);
        self::assertTrue($buf->get(3, 0)->skipDiff);
        self::assertSame(4, $endX);
    }

    #[Test]
    public function putStringClipsAtBufferEdge(): void
    {
        $buf = Buffer::empty(3, 1);
        $endX = $buf->putString(0, 0, 'abcde', Style::new());

        self::assertSame('a', $buf->get(0, 0)->char);
        self::assertSame('b', $buf->get(1, 0)->char);
        self::assertSame('c', $buf->get(2, 0)->char);
        self::assertSame(3, $endX);
    }

    #[Test]
    public function clearResetsAllCells(): void
    {
        $buf = Buffer::empty(5, 2);
        $buf->set(0, 0, 'X', Style::new()->bold());
        $buf->set(4, 1, 'Y', Style::new());

        $buf->clear();

        self::assertSame(' ', $buf->get(0, 0)->char);
        self::assertSame(' ', $buf->get(4, 1)->char);
    }

    #[Test]
    public function swapExchangesCellData(): void
    {
        $a = Buffer::empty(5, 3);
        $b = Buffer::empty(5, 3);
        $a->set(0, 0, 'A', Style::new());
        $b->set(0, 0, 'B', Style::new());

        $a->swap($b);

        self::assertSame('B', $a->get(0, 0)->char);
        self::assertSame('A', $b->get(0, 0)->char);
    }

    #[Test]
    public function resizePreservesExistingContent(): void
    {
        $buf = Buffer::empty(5, 3);
        $buf->set(1, 1, 'X', Style::new());

        $buf->resize(10, 5);

        self::assertSame(10, $buf->width);
        self::assertSame(5, $buf->height);
        self::assertSame('X', $buf->get(1, 1)->char);
    }

    #[Test]
    public function resizeSmallerClipsContent(): void
    {
        $buf = Buffer::empty(10, 5);
        $buf->set(8, 3, 'X', Style::new());

        $buf->resize(5, 3);

        self::assertSame(5, $buf->width);
        self::assertSame(3, $buf->height);
        self::assertSame(' ', $buf->get(4, 2)->char);
    }

    #[Test]
    public function fillSetsAreaCells(): void
    {
        $buf = Buffer::empty(10, 5);
        $style = Style::new()->bold();

        $buf->fill(Rect::of(2, 1, 3, 2), $style);

        self::assertSame(' ', $buf->get(2, 1)->char);
        self::assertTrue($buf->get(2, 1)->style->equals($style));
        self::assertSame(' ', $buf->get(4, 2)->char);
        self::assertTrue($buf->get(4, 2)->style->equals($style));
        self::assertFalse($buf->get(5, 1)->style->equals($style));
    }

    #[Test]
    public function dimPreservesCharactersAndTransparentCells(): void
    {
        $buf = Buffer::empty(5, 1);
        $buf->set(1, 0, 'A', Style::new());
        $buf->putString(2, 0, '漢', Style::new());

        $buf->dim(Rect::of(0, 0, 4, 1));

        self::assertSame(' ', $buf->get(0, 0)->char);
        self::assertTrue($buf->get(0, 0)->transparent);
        self::assertSame('A', $buf->get(1, 0)->char);
        self::assertTrue($buf->get(1, 0)->style->hasModifier(Modifier::Dim));
        self::assertSame('漢', $buf->get(2, 0)->char);
        self::assertTrue($buf->get(2, 0)->style->hasModifier(Modifier::Dim));
        self::assertSame('', $buf->get(3, 0)->char);
        self::assertTrue($buf->get(3, 0)->skipDiff);
        self::assertTrue($buf->get(3, 0)->style->hasModifier(Modifier::Dim));
    }

    #[Test]
    public function scrimDarkensCharactersAndMakesTransparentCellsOpaque(): void
    {
        $buf = Buffer::empty(3, 1);
        $scrim = Style::new()->bg('#050505');
        $buf->set(1, 0, 'A', Style::new());

        $buf->scrim(Rect::of(0, 0, 3, 1), $scrim);

        self::assertSame(' ', $buf->get(0, 0)->char);
        self::assertFalse($buf->get(0, 0)->transparent);
        self::assertTrue($buf->get(0, 0)->style->background?->equals(Color::hex('#050505')));
        self::assertSame('A', $buf->get(1, 0)->char);
        self::assertTrue($buf->get(1, 0)->style->hasModifier(Modifier::Dim));
        self::assertTrue($buf->get(1, 0)->style->background?->equals(Color::hex('#050505')));
    }

    #[Test]
    public function putStringPreservesExistingBackgroundWhenTextStyleHasNone(): void
    {
        $buf = Buffer::empty(3, 1);

        $buf->fill(Rect::sized(3, 1), Style::new()->bg('#111111'));
        $buf->putString(0, 0, 'A', Style::new()->fg('#ffffff'));

        self::assertTrue($buf->get(0, 0)->style->foreground?->equals(Color::hex('#ffffff')));
        self::assertTrue($buf->get(0, 0)->style->background?->equals(Color::hex('#111111')));
    }

    #[Test]
    public function putStringKeepsExplicitTextBackground(): void
    {
        $buf = Buffer::empty(3, 1);

        $buf->fill(Rect::sized(3, 1), Style::new()->bg('#111111'));
        $buf->putString(0, 0, 'A', Style::new()->fg('#ffffff')->bg('#222222'));

        self::assertTrue($buf->get(0, 0)->style->background?->equals(Color::hex('#222222')));
    }

    #[Test]
    public function setIgnoresOutOfBounds(): void
    {
        $buf = Buffer::empty(5, 3);
        $buf->set(-1, 0, 'X', Style::new());
        $buf->set(5, 0, 'Y', Style::new());
        $buf->set(0, -1, 'Z', Style::new());
        $buf->set(0, 3, 'W', Style::new());

        self::assertSame(' ', $buf->get(0, 0)->char);
    }
}
