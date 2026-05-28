<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Widget;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Widget\Box;
use Phalanx\Theatron\Widget\BoxStyle;
use Phalanx\Theatron\Widget\Text\Paragraph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoxTest extends TestCase
{
    #[Test]
    public function renders_single_border(): void
    {
        $inner = Paragraph::of('hi');
        $box = new Box($inner, BoxStyle::Single);

        $buf = Buffer::empty(10, 5);
        $box->render(Rect::sized(10, 5), $buf);

        self::assertSame('┌', $buf->get(0, 0)->char);
        self::assertSame('┐', $buf->get(9, 0)->char);
        self::assertSame('└', $buf->get(0, 4)->char);
        self::assertSame('┘', $buf->get(9, 4)->char);
        self::assertSame('─', $buf->get(1, 0)->char);
        self::assertSame('│', $buf->get(0, 1)->char);
    }

    #[Test]
    public function renders_inner_content(): void
    {
        $inner = Paragraph::of('XY');
        $box = new Box($inner, BoxStyle::Single);

        $buf = Buffer::empty(10, 5);
        $box->render(Rect::sized(10, 5), $buf);

        self::assertSame('X', $buf->get(1, 1)->char);
        self::assertSame('Y', $buf->get(2, 1)->char);
    }

    #[Test]
    public function renders_title(): void
    {
        $inner = Paragraph::of('');
        $box = new Box($inner, BoxStyle::Single, title: 'Test');

        $buf = Buffer::empty(20, 5);
        $box->render(Rect::sized(20, 5), $buf);

        self::assertSame(' ', $buf->get(1, 0)->char);
        self::assertSame('T', $buf->get(2, 0)->char);
        self::assertSame('e', $buf->get(3, 0)->char);
        self::assertSame('s', $buf->get(4, 0)->char);
        self::assertSame('t', $buf->get(5, 0)->char);
        self::assertSame(' ', $buf->get(6, 0)->char);
    }

    #[Test]
    public function rounded_border_uses_correct_chars(): void
    {
        $inner = Paragraph::of('');
        $box = new Box($inner, BoxStyle::Rounded);

        $buf = Buffer::empty(5, 3);
        $box->render(Rect::sized(5, 3), $buf);

        self::assertSame('╭', $buf->get(0, 0)->char);
        self::assertSame('╮', $buf->get(4, 0)->char);
        self::assertSame('╰', $buf->get(0, 2)->char);
        self::assertSame('╯', $buf->get(4, 2)->char);
    }

    #[Test]
    public function too_small_area_is_noop(): void
    {
        $inner = Paragraph::of('');
        $box = new Box($inner, BoxStyle::Single);

        $buf = Buffer::empty(1, 1);
        $box->render(Rect::sized(1, 1), $buf);

        self::assertSame(' ', $buf->get(0, 0)->char);
    }
}
