<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Tests\Unit\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;
use Phalanx\Terminal\Widget\Text\Line;
use Phalanx\Terminal\Widget\Text\Paragraph;
use Phalanx\Terminal\Widget\Text\Span;
use Phalanx\Terminal\Widget\Text\Truncation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParagraphTest extends TestCase
{
    #[Test]
    public function renders_simple_text(): void
    {
        $p = Paragraph::of("hello\nworld");

        $buf = Buffer::empty(10, 3);
        $p->render(Rect::sized(10, 3), $buf);

        self::assertSame('h', $buf->get(0, 0)->char);
        self::assertSame('w', $buf->get(0, 1)->char);
    }

    #[Test]
    public function clips_to_area_height(): void
    {
        $p = Paragraph::of("line1\nline2\nline3\nline4");

        $buf = Buffer::empty(10, 2);
        $p->render(Rect::sized(10, 2), $buf);

        self::assertSame('l', $buf->get(0, 0)->char);
        self::assertSame('l', $buf->get(0, 1)->char);
    }

    #[Test]
    public function truncates_with_ellipsis(): void
    {
        $p = Paragraph::of('this is a very long line');

        $buf = Buffer::empty(10, 1);
        $p->render(Rect::sized(10, 1), $buf);

        self::assertSame('.', $buf->get(7, 0)->char);
        self::assertSame('.', $buf->get(8, 0)->char);
        self::assertSame('.', $buf->get(9, 0)->char);
    }

    #[Test]
    public function scroll_offset_skips_lines(): void
    {
        $p = Paragraph::of("a\nb\nc\nd\ne");
        $p->scroll(2);

        $buf = Buffer::empty(5, 2);
        $p->render(Rect::sized(5, 2), $buf);

        self::assertSame('c', $buf->get(0, 0)->char);
        self::assertSame('d', $buf->get(0, 1)->char);
    }

    #[Test]
    public function from_lines_with_styled_spans(): void
    {
        $p = Paragraph::fromLines(
            Line::from(
                Span::styled('error', Style::new()->fg('red')),
                Span::plain(': something broke'),
            ),
        );

        $buf = Buffer::empty(30, 1);
        $p->render(Rect::sized(30, 1), $buf);

        self::assertSame('e', $buf->get(0, 0)->char);
        self::assertSame(':', $buf->get(5, 0)->char);
    }
}
