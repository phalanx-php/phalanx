<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Inputs;

use Closure;
use InvalidArgumentException;
use Phalanx\Tui\Tui\Inputs\Binding;
use Phalanx\Tui\Tui\Inputs\BindingActionKind;
use Phalanx\Tui\Tui\Core\RenderContext;
use Phalanx\Tui\Tui\Core\ScreenContext;
use Phalanx\Tui\Tui\Core\Component;
use Phalanx\Tui\Tui\Core\Screen;
use Phalanx\Tui\Tui\Inputs\Key;
use Phalanx\Tui\Tui\Inputs\KeyEvent;
use Phalanx\Tui\Tui\Tdom\Element\TextElement;
use Phalanx\Tui\Tui\Tdom\Renderable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Stub fixtures — satisfy interface contracts so ::class resolves to a valid
// class-string<Screen> / class-string<Component>. Never instantiated.

final class SpartaChatScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

final class OlympusDevToolsOverlay implements Component
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

// ---------------------------------------------------------------------------

/**
 * Fixtures use the Spartan lexicon: Leonidas, Thermopylae, Olympus, Sparta.
 */
final class BindingTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Factory shapes

    #[Test]
    public function ctrlFactorySetsCtrlFlag(): void
    {
        $b = Binding::ctrl('c');

        self::assertTrue($b->ctrl);
        self::assertFalse($b->alt);
        self::assertFalse($b->shift);
        self::assertSame('c', $b->key);
    }

    #[Test]
    public function altFactorySetsAltFlag(): void
    {
        $b = Binding::alt('x');

        self::assertFalse($b->ctrl);
        self::assertTrue($b->alt);
        self::assertFalse($b->shift);
        self::assertSame('x', $b->key);
    }

    #[Test]
    public function keyFactoryAcceptsNamedKey(): void
    {
        $b = Binding::key(Key::F12);

        self::assertFalse($b->ctrl);
        self::assertFalse($b->alt);
        self::assertFalse($b->shift);
        self::assertSame(Key::F12, $b->key);
    }

    #[Test]
    public function keyFactoryAcceptsCharString(): void
    {
        $b = Binding::key('s');

        self::assertSame('s', $b->key);
    }

    // -------------------------------------------------------------------------
    // Action variants

    #[Test]
    public function quitActionKind(): void
    {
        $b = Binding::ctrl('c')->quit();

        self::assertNotNull($b->action);
        self::assertTrue($b->action->isQuit());
        self::assertSame(BindingActionKind::Quit, $b->action->kind);
    }

    #[Test]
    public function workspaceActionKind(): void
    {
        $b = Binding::ctrl('1')->workspace(SpartaChatScreen::class);

        self::assertNotNull($b->action);
        self::assertTrue($b->action->isWorkspace());
        self::assertSame(SpartaChatScreen::class, $b->action->target);
    }

    #[Test]
    public function toggleActionKind(): void
    {
        $b = Binding::key(Key::F12)->toggle(OlympusDevToolsOverlay::class);

        self::assertNotNull($b->action);
        self::assertTrue($b->action->isToggle());
        self::assertSame(OlympusDevToolsOverlay::class, $b->action->target);
    }

    #[Test]
    public function actionKindWithStaticClosure(): void
    {
        $b = Binding::key('s')->action(static fn () => 'saved');

        self::assertNotNull($b->action);
        self::assertTrue($b->action->isAction());
        self::assertInstanceOf(Closure::class, $b->action->callback);
    }

    #[Test]
    public function nonStaticActionClosureThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/static/');

        Binding::key('s')->action(fn () => 'not static');
    }

    // -------------------------------------------------------------------------
    // Label fluency

    #[Test]
    public function labelReturnsNewInstance(): void
    {
        $original = Binding::ctrl('c')->quit();
        $labelled = $original->label('Quit');

        self::assertNull($original->label);
        self::assertSame('Quit', $labelled->label);
    }

    #[Test]
    public function labelPreservesKeyCombo(): void
    {
        $b = Binding::ctrl('q')->quit()->label('Leonidas');

        self::assertTrue($b->ctrl);
        self::assertSame('q', $b->key);
        self::assertSame('Leonidas', $b->label);
    }

    // -------------------------------------------------------------------------
    // Action fluency does not mutate original

    #[Test]
    public function actionMutatorClonesBinding(): void
    {
        $base = Binding::ctrl('c');
        $withQuit = $base->quit();

        self::assertNull($base->action);
        self::assertNotNull($withQuit->action);
        self::assertNotSame($base, $withQuit);
    }

    // -------------------------------------------------------------------------
    // Matching

    #[Test]
    public function matchesCtrlC(): void
    {
        $b = Binding::ctrl('c')->quit();
        $event = new KeyEvent(key: 'c', ctrl: true);

        self::assertTrue($b->matches($event));
    }

    #[Test]
    public function doesNotMatchWrongKey(): void
    {
        $b = Binding::ctrl('c')->quit();
        $event = new KeyEvent(key: 'x', ctrl: true);

        self::assertFalse($b->matches($event));
    }

    #[Test]
    public function doesNotMatchMissingCtrlModifier(): void
    {
        $b = Binding::ctrl('c')->quit();
        $event = new KeyEvent(key: 'c', ctrl: false);

        self::assertFalse($b->matches($event));
    }

    #[Test]
    public function matchesNamedKeyWithNoModifiers(): void
    {
        $b = Binding::key(Key::F12)->toggle(OlympusDevToolsOverlay::class);
        $event = new KeyEvent(key: Key::F12);

        self::assertTrue($b->matches($event));
    }

    #[Test]
    public function namedKeyDoesNotMatchStringKey(): void
    {
        // Key::Enter !== 'enter' as a value comparison — enum vs string.
        $b = Binding::key(Key::Enter)->quit();
        $event = new KeyEvent(key: 'enter');

        self::assertFalse($b->matches($event));
    }

    #[Test]
    public function matchesAltModifier(): void
    {
        $b = Binding::alt('h')->quit()->label('Thermopylae help');
        $event = new KeyEvent(key: 'h', alt: true);

        self::assertTrue($b->matches($event));
    }

    #[Test]
    public function extraModifierPreventsMatch(): void
    {
        $b = Binding::key('s')->action(static fn () => null);
        // Same key but with ctrl held — should not match a plain 's' binding.
        $event = new KeyEvent(key: 's', ctrl: true);

        self::assertFalse($b->matches($event));
    }

    // -------------------------------------------------------------------------
    // No action on fresh binding

    #[Test]
    public function freshBindingHasNullAction(): void
    {
        $b = Binding::key(Key::Escape);

        self::assertNull($b->action);
    }

    #[Test]
    public function matchesRequiresAllModifiers(): void
    {
        $b = Binding::ctrl('c');

        self::assertFalse(
            $b->matches(new KeyEvent(key: 'c', ctrl: true, alt: true)),
            'Extra alt modifier must prevent match',
        );
    }
}
