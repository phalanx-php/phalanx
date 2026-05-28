<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Input;

use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\MouseAction;
use Phalanx\Theatron\Input\MouseButton;
use Phalanx\Theatron\Input\MouseEvent;
use Phalanx\Theatron\Input\PasteEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventParserTest extends TestCase
{
    private EventParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EventParser();
    }

    #[Test]
    public function parses_printable_characters(): void
    {
        $events = $this->parser->parse('a');

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertSame('a', $events[0]->key);
        self::assertTrue($events[0]->isChar());
    }

    #[Test]
    public function parses_multiple_characters(): void
    {
        $events = $this->parser->parse('abc');

        self::assertCount(3, $events);
        self::assertSame('a', $events[0]->key);
        self::assertSame('b', $events[1]->key);
        self::assertSame('c', $events[2]->key);
    }

    #[Test]
    public function parses_enter(): void
    {
        $events = $this->parser->parse("\r");

        self::assertCount(1, $events);
        self::assertSame(Key::Enter, $events[0]->key);
    }

    #[Test]
    public function parses_tab(): void
    {
        $events = $this->parser->parse("\t");

        self::assertCount(1, $events);
        self::assertSame(Key::Tab, $events[0]->key);
    }

    #[Test]
    public function parses_backspace(): void
    {
        $events = $this->parser->parse("\x7F");

        self::assertCount(1, $events);
        self::assertSame(Key::Backspace, $events[0]->key);
    }

    #[Test]
    public function parses_ctrl_c(): void
    {
        $events = $this->parser->parse("\x03");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertSame('c', $events[0]->key);
        self::assertTrue($events[0]->ctrl);
    }

    #[Test]
    public function parses_ctrl_d(): void
    {
        $events = $this->parser->parse("\x04");

        self::assertCount(1, $events);
        self::assertSame('d', $events[0]->key);
        self::assertTrue($events[0]->ctrl);
    }

    #[Test]
    public function parses_arrow_keys(): void
    {
        $events = $this->parser->parse("\033[A");
        self::assertSame(Key::Up, $events[0]->key);

        $events = $this->parser->parse("\033[B");
        self::assertSame(Key::Down, $events[0]->key);

        $events = $this->parser->parse("\033[C");
        self::assertSame(Key::Right, $events[0]->key);

        $events = $this->parser->parse("\033[D");
        self::assertSame(Key::Left, $events[0]->key);
    }

    #[Test]
    public function parses_ss3_arrow_keys(): void
    {
        $events = $this->parser->parse("\033OA");
        self::assertSame(Key::Up, $events[0]->key);

        $events = $this->parser->parse("\033OB");
        self::assertSame(Key::Down, $events[0]->key);
    }

    #[Test]
    public function parses_home_end(): void
    {
        $events = $this->parser->parse("\033[H");
        self::assertSame(Key::Home, $events[0]->key);

        $events = $this->parser->parse("\033[F");
        self::assertSame(Key::End, $events[0]->key);
    }

    #[Test]
    public function parses_delete(): void
    {
        $events = $this->parser->parse("\033[3~");

        self::assertCount(1, $events);
        self::assertSame(Key::Delete, $events[0]->key);
    }

    #[Test]
    public function parses_page_up_down(): void
    {
        $events = $this->parser->parse("\033[5~");
        self::assertSame(Key::PageUp, $events[0]->key);

        $events = $this->parser->parse("\033[6~");
        self::assertSame(Key::PageDown, $events[0]->key);
    }

    #[Test]
    public function parses_function_keys_ss3(): void
    {
        $events = $this->parser->parse("\033OP");
        self::assertSame(Key::F1, $events[0]->key);

        $events = $this->parser->parse("\033OQ");
        self::assertSame(Key::F2, $events[0]->key);

        $events = $this->parser->parse("\033OR");
        self::assertSame(Key::F3, $events[0]->key);

        $events = $this->parser->parse("\033OS");
        self::assertSame(Key::F4, $events[0]->key);
    }

    #[Test]
    public function parses_function_keys_tilde(): void
    {
        $events = $this->parser->parse("\033[15~");
        self::assertSame(Key::F5, $events[0]->key);

        $events = $this->parser->parse("\033[17~");
        self::assertSame(Key::F6, $events[0]->key);
    }

    #[Test]
    public function parses_alt_key(): void
    {
        $events = $this->parser->parse("\033x");

        self::assertCount(1, $events);
        self::assertSame('x', $events[0]->key);
        self::assertTrue($events[0]->alt);
    }

    #[Test]
    public function parses_space(): void
    {
        $events = $this->parser->parse(' ');

        self::assertCount(1, $events);
        self::assertSame(Key::Space, $events[0]->key);
    }

    #[Test]
    public function parses_sgr_mouse_click(): void
    {
        $events = $this->parser->parse("\033[<0;10;5M");

        self::assertCount(1, $events);
        self::assertInstanceOf(MouseEvent::class, $events[0]);
        self::assertSame(MouseButton::Left, $events[0]->button);
        self::assertSame(MouseAction::Press, $events[0]->action);
        self::assertSame(9, $events[0]->x);
        self::assertSame(4, $events[0]->y);
    }

    #[Test]
    public function parses_sgr_mouse_release(): void
    {
        $events = $this->parser->parse("\033[<0;10;5m");

        self::assertCount(1, $events);
        self::assertInstanceOf(MouseEvent::class, $events[0]);
        self::assertSame(MouseButton::Left, $events[0]->button);
        self::assertSame(MouseAction::Release, $events[0]->action);
    }

    #[Test]
    public function parses_scroll_up(): void
    {
        $events = $this->parser->parse("\033[<64;10;5M");

        self::assertCount(1, $events);
        self::assertInstanceOf(MouseEvent::class, $events[0]);
        self::assertSame(MouseButton::ScrollUp, $events[0]->button);
    }

    #[Test]
    public function parses_scroll_down(): void
    {
        $events = $this->parser->parse("\033[<65;10;5M");

        self::assertCount(1, $events);
        self::assertInstanceOf(MouseEvent::class, $events[0]);
        self::assertSame(MouseButton::ScrollDown, $events[0]->button);
    }

    #[Test]
    public function parses_bracketed_paste(): void
    {
        $events = $this->parser->parse("\033[200~hello world\033[201~");

        self::assertCount(1, $events);
        self::assertInstanceOf(PasteEvent::class, $events[0]);
        self::assertSame('hello world', $events[0]->content);
    }

    #[Test]
    public function handles_split_escape_sequence(): void
    {
        $events1 = $this->parser->parse("\033");
        self::assertCount(0, $events1);

        $events2 = $this->parser->parse("[A");
        self::assertCount(1, $events2);
        self::assertSame(Key::Up, $events2[0]->key);
    }

    #[Test]
    public function handles_split_paste(): void
    {
        $events1 = $this->parser->parse("\033[200~hello");
        self::assertCount(0, $events1);

        $events2 = $this->parser->parse(" world\033[201~");
        self::assertCount(1, $events2);
        self::assertInstanceOf(PasteEvent::class, $events2[0]);
        self::assertSame('hello world', $events2[0]->content);
    }

    #[Test]
    public function parses_utf8_characters(): void
    {
        $events = $this->parser->parse('ñ');

        self::assertCount(1, $events);
        self::assertSame('ñ', $events[0]->key);
    }

    #[Test]
    public function mixed_input_stream(): void
    {
        $events = $this->parser->parse("a\033[Ab\r");

        self::assertCount(4, $events);
        self::assertSame('a', $events[0]->key);
        self::assertSame(Key::Up, $events[1]->key);
        self::assertSame('b', $events[2]->key);
        self::assertSame(Key::Enter, $events[3]->key);
    }
}
