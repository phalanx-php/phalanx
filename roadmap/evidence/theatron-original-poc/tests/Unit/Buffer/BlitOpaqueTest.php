<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Buffer;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Style\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlitOpaqueTest extends TestCase
{
    #[Test]
    public function blit_opaque_skips_transparent_cells(): void
    {
        $style = Style::new();

        $source = Buffer::empty(3, 1);
        $source->set(1, 0, 'S', $style);

        $target = Buffer::filled(3, 1, 'T', $style);
        $target->blitOpaque($source, 0, 0);

        self::assertSame('T', $target->get(0, 0)->char);
        self::assertSame('S', $target->get(1, 0)->char);
        self::assertSame('T', $target->get(2, 0)->char);
    }

    #[Test]
    public function blit_opaque_copies_opaque_cells_like_blit_full(): void
    {
        $style = Style::new();

        $source = Buffer::filled(3, 2, 'Z', $style);

        $targetFull = Buffer::empty(3, 2);
        $targetFull->blitFull($source, 0, 0);

        $targetOpaque = Buffer::empty(3, 2);
        $targetOpaque->blitOpaque($source, 0, 0);

        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 3; $x++) {
                self::assertSame($targetFull->get($x, $y)->char, $targetOpaque->get($x, $y)->char);
            }
        }
    }

    #[Test]
    public function blit_opaque_respects_dest_bounds(): void
    {
        $style = Style::new();

        $source = Buffer::filled(5, 5, 'X', $style);
        $target = Buffer::empty(3, 3);

        // No crash, no out-of-bounds write — the buffer's set() guards on width/height
        $target->blitOpaque($source, 0, 0);

        self::assertSame('X', $target->get(2, 2)->char);
    }

    #[Test]
    public function blit_opaque_at_offset(): void
    {
        $style = Style::new();

        $source = Buffer::filled(2, 1, 'O', $style);

        $target = Buffer::filled(5, 1, 'B', $style);
        $target->blitOpaque($source, 2, 0);

        self::assertSame('B', $target->get(0, 0)->char);
        self::assertSame('B', $target->get(1, 0)->char);
        self::assertSame('O', $target->get(2, 0)->char);
        self::assertSame('O', $target->get(3, 0)->char);
        self::assertSame('B', $target->get(4, 0)->char);
    }
}
