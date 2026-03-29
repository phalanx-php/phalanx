<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Tests\Unit\Buffer;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;
use Phalanx\Terminal\Widget\Text\Line;
use Phalanx\Terminal\Widget\Text\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BufferTest extends TestCase
{
    #[Test]
    public function empty_buffer_has_space_cells(): void
    {
        $buf = Buffer::empty(3, 2);

        self::assertSame(' ', $buf->get(0, 0)->char);
        self::assertSame(' ', $buf->get(2, 1)->char);
        self::assertSame(3, $buf->width);
        self::assertSame(2, $buf->height);
    }

    #[Test]
    public function set_writes_cell(): void
    {
        $buf = Buffer::empty(5, 5);
        $style = Style::new()->fg('red');

        $buf->set(2, 3, 'X', $style);

        self::assertSame('X', $buf->get(2, 3)->char);
        self::assertTrue($buf->get(2, 3)->style->equals($style));
    }

    #[Test]
    public function set_out_of_bounds_is_noop(): void
    {
        $buf = Buffer::empty(3, 3);

        $buf->set(-1, 0, 'X', Style::new());
        $buf->set(0, 5, 'X', Style::new());
        $buf->set(3, 0, 'X', Style::new());

        self::assertSame(' ', $buf->get(0, 0)->char);
    }

    #[Test]
    public function put_string_writes_characters(): void
    {
        $buf = Buffer::empty(10, 1);
        $style = Style::new()->bold();

        $endX = $buf->putString(2, 0, 'hello', $style);

        self::assertSame(7, $endX);
        self::assertSame('h', $buf->get(2, 0)->char);
        self::assertSame('e', $buf->get(3, 0)->char);
        self::assertSame('o', $buf->get(6, 0)->char);
        self::assertSame(' ', $buf->get(7, 0)->char);
    }

    #[Test]
    public function put_string_clips_at_width(): void
    {
        $buf = Buffer::empty(5, 1);

        $endX = $buf->putString(3, 0, 'hello', Style::new());

        self::assertSame(5, $endX);
        self::assertSame('h', $buf->get(3, 0)->char);
        self::assertSame('e', $buf->get(4, 0)->char);
    }

    #[Test]
    public function diff_returns_only_changed_cells(): void
    {
        $prev = Buffer::empty(3, 2);
        $curr = Buffer::empty(3, 2);

        $curr->set(1, 0, 'A', Style::new());
        $curr->set(2, 1, 'B', Style::new()->bold());

        $updates = $curr->diff($prev);

        self::assertCount(2, $updates);
        self::assertSame(1, $updates[0]->x);
        self::assertSame(0, $updates[0]->y);
        self::assertSame('A', $updates[0]->char);
        self::assertSame(2, $updates[1]->x);
        self::assertSame(1, $updates[1]->y);
        self::assertSame('B', $updates[1]->char);
    }

    #[Test]
    public function diff_returns_empty_for_identical_buffers(): void
    {
        $prev = Buffer::empty(5, 5);
        $curr = Buffer::empty(5, 5);

        self::assertSame([], $curr->diff($prev));
    }

    #[Test]
    public function fill_applies_style_to_area(): void
    {
        $buf = Buffer::empty(5, 5);
        $style = Style::new()->bg('blue');

        $buf->fill(Rect::of(1, 1, 3, 2), $style);

        self::assertTrue($buf->get(1, 1)->style->equals($style));
        self::assertTrue($buf->get(3, 2)->style->equals($style));
        self::assertFalse($buf->get(0, 0)->style->equals($style));
    }

    #[Test]
    public function resize_preserves_content(): void
    {
        $buf = Buffer::empty(3, 3);
        $buf->set(1, 1, 'X', Style::new());

        $buf->resize(5, 5);

        self::assertSame(5, $buf->width);
        self::assertSame(5, $buf->height);
        self::assertSame('X', $buf->get(1, 1)->char);
        self::assertSame(' ', $buf->get(4, 4)->char);
    }

    #[Test]
    public function put_line_writes_spans(): void
    {
        $buf = Buffer::empty(20, 1);
        $line = Line::from(
            Span::styled('hello', Style::new()->fg('red')),
            Span::plain(' world'),
        );

        $buf->putLine(0, 0, $line, 20);

        self::assertSame('h', $buf->get(0, 0)->char);
        self::assertSame(' ', $buf->get(5, 0)->char);
        self::assertSame('w', $buf->get(6, 0)->char);
    }

    #[Test]
    public function blit_copies_region_from_source(): void
    {
        $src = Buffer::empty(3, 3);
        $src->set(0, 0, 'A', Style::new());
        $src->set(1, 1, 'B', Style::new());

        $dst = Buffer::empty(5, 5);
        $dst->blit($src, Rect::sized(3, 3), 1, 1);

        self::assertSame('A', $dst->get(1, 1)->char);
        self::assertSame('B', $dst->get(2, 2)->char);
        self::assertSame(' ', $dst->get(0, 0)->char);
    }

    #[Test]
    public function swap_exchanges_buffers(): void
    {
        $a = Buffer::empty(3, 3);
        $a->set(0, 0, 'A', Style::new());

        $b = Buffer::empty(3, 3);
        $b->set(0, 0, 'B', Style::new());

        $a->swap($b);

        self::assertSame('B', $a->get(0, 0)->char);
        self::assertSame('A', $b->get(0, 0)->char);
    }
}
