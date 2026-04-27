<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Widget;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Widget\InputLine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InputLineTest extends TestCase
{
    #[Test]
    public function typing_characters_updates_text(): void
    {
        $input = new InputLine();

        $input->handleKey(new KeyEvent('h'));
        $input->handleKey(new KeyEvent('i'));

        self::assertSame('hi', $input->text);
    }

    #[Test]
    public function enter_returns_and_clears(): void
    {
        $input = new InputLine();
        $input->handleKey(new KeyEvent('a'));
        $input->handleKey(new KeyEvent('b'));

        $result = $input->handleKey(new KeyEvent(Key::Enter));

        self::assertSame('ab', $result);
        self::assertSame('', $input->text);
    }

    #[Test]
    public function backspace_deletes_before_cursor(): void
    {
        $input = new InputLine();
        $input->handleKey(new KeyEvent('a'));
        $input->handleKey(new KeyEvent('b'));
        $input->handleKey(new KeyEvent('c'));
        $input->handleKey(new KeyEvent(Key::Backspace));

        self::assertSame('ab', $input->text);
    }

    #[Test]
    public function arrow_keys_move_cursor(): void
    {
        $input = new InputLine();
        $input->handleKey(new KeyEvent('a'));
        $input->handleKey(new KeyEvent('b'));
        $input->handleKey(new KeyEvent('c'));
        $input->handleKey(new KeyEvent(Key::Left));
        $input->handleKey(new KeyEvent(Key::Left));
        $input->handleKey(new KeyEvent('X'));

        self::assertSame('aXbc', $input->text);
    }

    #[Test]
    public function home_end_keys(): void
    {
        $input = new InputLine();
        $input->setValue('hello');
        $input->handleKey(new KeyEvent(Key::Home));
        $input->handleKey(new KeyEvent('X'));

        self::assertSame('Xhello', $input->text);

        $input->handleKey(new KeyEvent(Key::End));
        $input->handleKey(new KeyEvent('Y'));

        self::assertSame('XhelloY', $input->text);
    }

    #[Test]
    public function delete_key_removes_after_cursor(): void
    {
        $input = new InputLine();
        $input->setValue('abc');
        $input->handleKey(new KeyEvent(Key::Home));
        $input->handleKey(new KeyEvent(Key::Delete));

        self::assertSame('bc', $input->text);
    }

    #[Test]
    public function renders_prompt_and_text(): void
    {
        $input = new InputLine(prompt: '> ');
        $input->setValue('hello');

        $buf = Buffer::empty(20, 1);
        $input->render(Rect::sized(20, 1), $buf);

        self::assertSame('>', $buf->get(0, 0)->char);
        self::assertSame(' ', $buf->get(1, 0)->char);
        self::assertSame('h', $buf->get(2, 0)->char);
        self::assertSame('o', $buf->get(6, 0)->char);
    }
}
