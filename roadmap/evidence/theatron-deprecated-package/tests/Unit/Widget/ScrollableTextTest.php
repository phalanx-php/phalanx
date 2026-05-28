<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Widget;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Widget\ScrollableText;
use Phalanx\Theatron\Widget\Text\Line;
use Phalanx\Theatron\Widget\Text\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScrollableTextTest extends TestCase
{
    #[Test]
    public function renders_appended_text(): void
    {
        $widget = new ScrollableText();
        $widget->append('hello');

        $buf = Buffer::empty(10, 3);
        $widget->render(Rect::sized(10, 3), $buf);

        self::assertSame('h', $buf->get(0, 0)->char);
        self::assertSame('o', $buf->get(4, 0)->char);
    }

    #[Test]
    public function follows_tail_by_default(): void
    {
        $widget = new ScrollableText();

        for ($i = 0; $i < 10; $i++) {
            $widget->append("line {$i}");
        }

        $buf = Buffer::empty(10, 3);
        $widget->render(Rect::sized(10, 3), $buf);

        self::assertSame('l', $buf->get(0, 0)->char);
        self::assertSame('i', $buf->get(1, 0)->char);
        self::assertSame('n', $buf->get(2, 0)->char);
        self::assertSame('e', $buf->get(3, 0)->char);
        self::assertSame(' ', $buf->get(4, 0)->char);
        self::assertSame('7', $buf->get(5, 0)->char);
    }

    #[Test]
    public function scroll_up_disables_follow(): void
    {
        $widget = new ScrollableText();

        for ($i = 0; $i < 10; $i++) {
            $widget->append("line {$i}");
        }

        $widget->scrollUp(3);

        self::assertFalse($widget->isFollowingTail);
    }

    #[Test]
    public function scroll_to_bottom_re_enables_follow(): void
    {
        $widget = new ScrollableText();
        $widget->append('test');
        $widget->scrollUp();
        $widget->scrollToBottom();

        self::assertTrue($widget->isFollowingTail);
    }

    #[Test]
    public function append_token_extends_last_line(): void
    {
        $widget = new ScrollableText();
        $widget->appendToken('hello');
        $widget->appendToken(' world');

        self::assertSame(1, $widget->lineCount);

        $buf = Buffer::empty(20, 1);
        $widget->render(Rect::sized(20, 1), $buf);

        self::assertSame('h', $buf->get(0, 0)->char);
        self::assertSame(' ', $buf->get(5, 0)->char);
        self::assertSame('w', $buf->get(6, 0)->char);
    }

    #[Test]
    public function append_token_with_newline_creates_new_lines(): void
    {
        $widget = new ScrollableText();
        $widget->appendToken("hello\nworld");

        self::assertSame(2, $widget->lineCount);
    }

    #[Test]
    public function clear_resets_state(): void
    {
        $widget = new ScrollableText();
        $widget->append('test');
        $widget->scrollUp();
        $widget->clear();

        self::assertSame(0, $widget->lineCount);
        self::assertTrue($widget->isFollowingTail);
    }

    #[Test]
    public function enforces_max_lines(): void
    {
        $widget = new ScrollableText(maxLines: 5);

        for ($i = 0; $i < 10; $i++) {
            $widget->append("line {$i}");
        }

        self::assertSame(5, $widget->lineCount);
    }
}
