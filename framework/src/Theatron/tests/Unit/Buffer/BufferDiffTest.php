<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Buffer;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Style\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BufferDiffTest extends TestCase
{
    #[Test]
    public function diffDetectsChangedCells(): void
    {
        $a = Buffer::empty(10, 5);
        $b = Buffer::empty(10, 5);
        $b->set(3, 2, 'X', Style::new()->bold());

        $updates = $b->diff($a);

        self::assertCount(1, $updates);
        self::assertSame(3, $updates[0]->x);
        self::assertSame(2, $updates[0]->y);
        self::assertSame('X', $updates[0]->char);
    }

    #[Test]
    public function identicalBuffersProduceNoDiff(): void
    {
        $a = Buffer::empty(10, 5);
        $b = Buffer::empty(10, 5);

        self::assertSame([], $b->diff($a));
    }

    #[Test]
    public function blitCopiesSourceRegionToDestination(): void
    {
        $src = Buffer::filled(4, 4, '#', Style::new());
        $dst = Buffer::empty(10, 10);

        $dst->blit($src, Rect::sized(4, 4), 2, 3);

        self::assertSame('#', $dst->get(2, 3)->char);
        self::assertSame('#', $dst->get(5, 6)->char);
        self::assertSame(' ', $dst->get(1, 3)->char);
    }
}
