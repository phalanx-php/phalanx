<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Kit;

use Phalanx\Tui\Inputs\Key;
use Phalanx\Tui\Inputs\KeyEvent;
use Phalanx\Tui\Kit\InputComposer;
use Phalanx\Tui\Reactive\Signal;
use Phalanx\Tui\Tdom\Element\InputElement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextInputBehaviorTest extends TestCase
{
    #[Test]
    public function appendsCharacterToSignal(): void
    {
        $fixture = new TextInputFixture(new Signal(''));
        $handled = $fixture->handle(new KeyEvent('a'));

        self::assertTrue($handled);
        self::assertNotNull($fixture->signal());
        self::assertSame('a', $fixture->signal()->get());
    }

    #[Test]
    public function backspaceTrimsLastCharacter(): void
    {
        $fixture = new TextInputFixture(new Signal('hello'));
        $handled = $fixture->handle(new KeyEvent(Key::Backspace));

        self::assertTrue($handled);
        self::assertNotNull($fixture->signal());
        self::assertSame('hell', $fixture->signal()->get());
    }

    #[Test]
    public function returnsFalseWhenNonPrintableKeyPressed(): void
    {
        $fixture = new TextInputFixture(new Signal(''));
        $handled = $fixture->handle(new KeyEvent(Key::Enter));

        self::assertFalse($handled);
    }

    #[Test]
    public function returnsFalseWhenSignalIsNull(): void
    {
        $fixture = new TextInputFixture(null);

        self::assertFalse($fixture->handle(new KeyEvent('a')));
    }

    #[Test]
    public function backspaceOnEmptyStringStaysEmpty(): void
    {
        $fixture = new TextInputFixture(new Signal(''));
        $handled = $fixture->handle(new KeyEvent(Key::Backspace));

        self::assertTrue($handled);
        self::assertNotNull($fixture->signal());
        self::assertSame('', $fixture->signal()->get());
    }

    #[Test]
    public function spaceKeyAppendsSpace(): void
    {
        $fixture = new TextInputFixture(new Signal('hello'));
        $handled = $fixture->handle(new KeyEvent(Key::Space));

        self::assertTrue($handled);
        self::assertNotNull($fixture->signal());
        self::assertSame('hello ', $fixture->signal()->get());
    }

    #[Test]
    public function backspaceRemovesLastMultiByteCharacter(): void
    {
        $fixture = new TextInputFixture(new Signal('αβγ'));
        $handled = $fixture->handle(new KeyEvent(Key::Backspace));

        self::assertTrue($handled);
        self::assertNotNull($fixture->signal());
        self::assertSame('αβ', $fixture->signal()->get());
    }

    #[Test]
    public function insertsAtCursorAndMovesCursor(): void
    {
        $fixture = new TextInputFixture(new Signal('helo'), new Signal(2));

        self::assertTrue($fixture->handle(new KeyEvent('l')));

        self::assertNotNull($fixture->signal());
        self::assertNotNull($fixture->cursor());
        self::assertSame('hello', $fixture->signal()->get());
        self::assertSame(3, $fixture->cursor()->get());
    }

    #[Test]
    public function ctrlUKillsToLineStart(): void
    {
        $fixture = new TextInputFixture(new Signal("first\nsecond"), new Signal(12), new Signal(''));

        self::assertTrue($fixture->handle(new KeyEvent('u', ctrl: true)));

        self::assertNotNull($fixture->signal());
        self::assertNotNull($fixture->cursor());
        self::assertNotNull($fixture->killRing());
        self::assertSame("first\n", $fixture->signal()->get());
        self::assertSame(6, $fixture->cursor()->get());
        self::assertSame('second', $fixture->killRing()->get());
    }

    #[Test]
    public function ctrlKKillsToLineEndAndCtrlYYanks(): void
    {
        $fixture = new TextInputFixture(new Signal('hello world'), new Signal(6), new Signal(''));

        self::assertTrue($fixture->handle(new KeyEvent('k', ctrl: true)));
        self::assertTrue($fixture->handle(new KeyEvent('y', ctrl: true)));

        self::assertNotNull($fixture->signal());
        self::assertNotNull($fixture->cursor());
        self::assertSame('hello world', $fixture->signal()->get());
        self::assertSame(11, $fixture->cursor()->get());
    }

    #[Test]
    public function altMovementAndWordDeletionOperateOnWords(): void
    {
        $fixture = new TextInputFixture(new Signal('alpha beta gamma'), new Signal(0), new Signal(''));

        self::assertTrue($fixture->handle(new KeyEvent('f', alt: true)));
        self::assertTrue($fixture->handle(new KeyEvent('f', alt: true)));
        self::assertTrue($fixture->handle(new KeyEvent('d', alt: true)));

        self::assertNotNull($fixture->signal());
        self::assertNotNull($fixture->cursor());
        self::assertSame('alpha beta', $fixture->signal()->get());
        self::assertSame(10, $fixture->cursor()->get());
    }

    #[Test]
    public function altArrowsMoveByWordWithoutSelection(): void
    {
        $fixture = new TextInputFixture(new Signal('alpha beta'), new Signal(10));

        self::assertTrue($fixture->handle(new KeyEvent(Key::Left, alt: true)));
        self::assertTrue($fixture->handle(new KeyEvent(Key::Right, alt: true)));

        self::assertNotNull($fixture->cursor());
        self::assertNotNull($fixture->selectionAnchor());
        self::assertSame(10, $fixture->cursor()->get());
        self::assertNull($fixture->selectionAnchor()->get());
    }

    #[Test]
    public function shiftArrowsSelectTextAndTypingReplacesSelection(): void
    {
        $fixture = new TextInputFixture(new Signal('hello'), new Signal(5));

        self::assertTrue($fixture->handle(new KeyEvent(Key::Left, shift: true)));
        self::assertTrue($fixture->handle(new KeyEvent(Key::Left, shift: true)));
        self::assertTrue($fixture->handle(new KeyEvent('X')));

        self::assertNotNull($fixture->signal());
        self::assertNotNull($fixture->cursor());
        self::assertNotNull($fixture->selectionAnchor());
        self::assertSame('helX', $fixture->signal()->get());
        self::assertSame(4, $fixture->cursor()->get());
        self::assertNull($fixture->selectionAnchor()->get());
    }

    #[Test]
    public function plainArrowCollapsesSelectionToRangeEdge(): void
    {
        $fixture = new TextInputFixture(new Signal('hello'), new Signal(5));

        self::assertTrue($fixture->handle(new KeyEvent(Key::Left, shift: true)));
        self::assertTrue($fixture->handle(new KeyEvent(Key::Left, shift: true)));
        self::assertTrue($fixture->handle(new KeyEvent(Key::Left)));

        self::assertNotNull($fixture->cursor());
        self::assertNotNull($fixture->selectionAnchor());
        self::assertSame(3, $fixture->cursor()->get());
        self::assertNull($fixture->selectionAnchor()->get());
    }

    #[Test]
    public function shiftAltArrowsSelectWords(): void
    {
        $fixture = new TextInputFixture(new Signal('alpha beta gamma'), new Signal(16));

        self::assertTrue($fixture->handle(new KeyEvent(Key::Left, alt: true, shift: true)));
        self::assertTrue($fixture->handle(new KeyEvent(Key::Delete)));

        self::assertNotNull($fixture->signal());
        self::assertNotNull($fixture->cursor());
        self::assertSame('alpha beta ', $fixture->signal()->get());
        self::assertSame(11, $fixture->cursor()->get());
    }

    #[Test]
    public function killCommandOnSelectionStoresSelectedTextInKillRing(): void
    {
        $fixture = new TextInputFixture(new Signal('alpha beta'), new Signal(10), new Signal(''));

        self::assertTrue($fixture->handle(new KeyEvent(Key::Left, alt: true, shift: true)));
        self::assertTrue($fixture->handle(new KeyEvent('w', ctrl: true)));

        self::assertNotNull($fixture->signal());
        self::assertNotNull($fixture->cursor());
        self::assertNotNull($fixture->killRing());
        self::assertSame('alpha ', $fixture->signal()->get());
        self::assertSame(6, $fixture->cursor()->get());
        self::assertSame('beta', $fixture->killRing()->get());
    }

    #[Test]
    public function inputComposerRendersEditableInputState(): void
    {
        $composer = InputComposer::empty();

        self::assertTrue($composer->handleInput(new KeyEvent('h')));
        self::assertTrue($composer->handleInput(new KeyEvent('i')));
        self::assertTrue($composer->handleInput(new KeyEvent(Key::Left, shift: true)));

        $renderable = $composer(new NullRenderContext());

        self::assertInstanceOf(InputElement::class, $renderable);
        self::assertSame('hi', $renderable->value);
        self::assertSame(1, $renderable->cursor);
        self::assertSame(1, $renderable->selectionStart);
        self::assertSame(2, $renderable->selectionEnd);
    }

    #[Test]
    public function inputComposerSubmitsNonEmptyDraftsAndClearsText(): void
    {
        $submitted = [];
        $composer = InputComposer::empty(onSubmit: static function (string $draft) use (&$submitted): void {
            $submitted[] = $draft;
        });

        self::assertTrue($composer->handleInput(new KeyEvent('h')));
        self::assertTrue($composer->handleInput(new KeyEvent('i')));
        self::assertTrue($composer->handleInput(new KeyEvent(Key::Enter)));
        self::assertTrue($composer->handleInput(new KeyEvent(Key::Enter)));

        $renderable = $composer(new NullRenderContext());

        self::assertSame(['hi'], $submitted);
        self::assertInstanceOf(InputElement::class, $renderable);
        self::assertSame('', $renderable->value);
        self::assertSame(0, $renderable->cursor);
    }
}
