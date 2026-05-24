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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventParserTest extends TestCase
{
    private EventParser $parser;

    /** @return iterable<string, array{string, Key, bool, bool, bool}> */
    public static function modifiedArrowProvider(): iterable
    {
        $directions = [
            'up' => ['A', Key::Up],
            'down' => ['B', Key::Down],
            'right' => ['C', Key::Right],
            'left' => ['D', Key::Left],
        ];

        foreach ($directions as $direction => [$suffix, $key]) {
            yield "shift {$direction}" => ["\033[1;2{$suffix}", $key, false, false, true];
            yield "alt {$direction}" => ["\033[1;3{$suffix}", $key, false, true, false];
            yield "ctrl {$direction}" => ["\033[1;5{$suffix}", $key, true, false, false];
        }
    }

    /** @return iterable<string, array{string, Key}> */
    public static function superArrowFallbackProvider(): iterable
    {
        $directions = [
            'up' => ['A', Key::Up],
            'down' => ['B', Key::Down],
            'right' => ['C', Key::Right],
            'left' => ['D', Key::Left],
        ];

        foreach ($directions as $direction => [$suffix, $key]) {
            yield "super {$direction}" => ["\033[1;9{$suffix}", $key];
        }
    }

    #[Test]
    public function csiArrowUp(): void
    {
        $events = $this->parser->parse("\033[A");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is(Key::Up));
    }

    #[Test]
    public function csiArrowDown(): void
    {
        $events = $this->parser->parse("\033[B");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is(Key::Down));
    }

    #[Test]
    public function csiArrowRight(): void
    {
        $events = $this->parser->parse("\033[C");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is(Key::Right));
    }

    #[Test]
    public function csiArrowLeft(): void
    {
        $events = $this->parser->parse("\033[D");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is(Key::Left));
    }

    #[Test]
    public function shiftTab(): void
    {
        $events = $this->parser->parse("\033[Z");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is(Key::Tab));
        self::assertTrue($events[0]->shift);
    }

    #[Test]
    public function ctrlKey(): void
    {
        $events = $this->parser->parse("\x03");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->ctrl);
        self::assertTrue($events[0]->is("c"));
    }

    #[Test]
    public function utf8Character(): void
    {
        $events = $this->parser->parse("å");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is("å"));
    }

    #[Test]
    public function bracketedPaste(): void
    {
        $events = $this->parser->parse("\033[200~hello world\033[201~");

        self::assertCount(1, $events);
        self::assertInstanceOf(PasteEvent::class, $events[0]);
        self::assertSame("hello world", $events[0]->content);
    }

    #[Test]
    public function splitBracketedPasteBuffersUntilEndMarker(): void
    {
        self::assertSame([], $this->parser->parse("\033[200~hello "));
        self::assertTrue($this->parser->hasPending());

        $events = $this->parser->parse("world\033[201~");

        self::assertCount(1, $events);
        self::assertInstanceOf(PasteEvent::class, $events[0]);
        self::assertSame("hello world", $events[0]->content);
        self::assertFalse($this->parser->hasPending());
    }

    #[Test]
    public function splitBracketedPasteStartSequenceIsBuffered(): void
    {
        self::assertSame([], $this->parser->parse("\033[200"));
        self::assertTrue($this->parser->hasPending());

        self::assertSame([], $this->parser->parse("~hello"));
        self::assertTrue($this->parser->hasPending());

        $events = $this->parser->parse("\033[201~");

        self::assertCount(1, $events);
        self::assertInstanceOf(PasteEvent::class, $events[0]);
        self::assertSame("hello", $events[0]->content);
        self::assertFalse($this->parser->hasPending());
    }

    #[Test]
    public function trailingInputAfterBracketedPasteIsParsed(): void
    {
        $events = $this->parser->parse("\033[200~hello\033[201~x");

        self::assertCount(2, $events);
        self::assertInstanceOf(PasteEvent::class, $events[0]);
        self::assertSame("hello", $events[0]->content);
        self::assertInstanceOf(KeyEvent::class, $events[1]);
        self::assertTrue($events[1]->is("x"));
    }

    #[Test]
    public function mouseSgrPress(): void
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
    public function mouseSgrRelease(): void
    {
        $events = $this->parser->parse("\033[<0;10;5m");

        self::assertCount(1, $events);
        self::assertInstanceOf(MouseEvent::class, $events[0]);
        self::assertSame(MouseButton::Left, $events[0]->button);
        self::assertSame(MouseAction::Release, $events[0]->action);
    }

    #[Test]
    public function enterKey(): void
    {
        $events = $this->parser->parse("\r");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is(Key::Enter));
    }

    #[Test]
    public function backspaceKey(): void
    {
        $events = $this->parser->parse("\x7F");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is(Key::Backspace));
    }

    #[Test]
    public function multipleEventsInOneParse(): void
    {
        $events = $this->parser->parse("ab");

        self::assertCount(2, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertInstanceOf(KeyEvent::class, $events[1]);
        self::assertTrue($events[0]->is("a"));
        self::assertTrue($events[1]->is("b"));
    }

    #[Test]
    public function incompleteEscapeReturnsNoEvent(): void
    {
        $events = $this->parser->parse("\033");

        self::assertCount(0, $events);
        self::assertTrue($this->parser->hasPending());
    }

    #[Test]
    public function flushIncompleteEscapeAsEscape(): void
    {
        $this->parser->parse("\033");
        $flushed = $this->parser->flush();

        self::assertCount(1, $flushed);
        self::assertInstanceOf(KeyEvent::class, $flushed[0]);
        self::assertTrue($flushed[0]->is(Key::Escape));
    }

    #[Test]
    public function deleteViaTilde(): void
    {
        $events = $this->parser->parse("\033[3~");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is(Key::Delete));
    }

    #[Test]
    public function shiftArrowModifier(): void
    {
        $events = $this->parser->parse("\033[1;2A");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertTrue($events[0]->is(Key::Up));
        self::assertTrue($events[0]->shift);
        self::assertFalse($events[0]->ctrl);
        self::assertFalse($events[0]->alt);
    }

    #[Test]
    #[DataProvider('modifiedArrowProvider')]
    public function modifiedArrowMatrixPreservesSupportedModifierState(
        string $input,
        Key $key,
        bool $ctrl,
        bool $alt,
        bool $shift,
    ): void {
        $event = $this->parseSingleKey($input);

        self::assertTrue($event->is($key));
        self::assertSame($ctrl, $event->ctrl);
        self::assertSame($alt, $event->alt);
        self::assertSame($shift, $event->shift);
    }

    #[Test]
    #[DataProvider('superArrowFallbackProvider')]
    public function superArrowModifierCurrentlyHasNoDistinctKeyEventState(string $input, Key $key): void
    {
        $event = $this->parseSingleKey($input);

        self::assertTrue($event->is($key));
        self::assertTrue($event->ctrl);
        self::assertFalse($event->alt);
        self::assertFalse($event->shift);
    }

    protected function setUp(): void
    {
        $this->parser = new EventParser();
    }

    private function parseSingleKey(string $input): KeyEvent
    {
        $events = $this->parser->parse($input);

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);

        return $events[0];
    }
}
