<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Inputs;

use Phalanx\Tui\Tui\Inputs\Binding;
use Phalanx\Tui\Tui\Inputs\BindingRegistry;
use Phalanx\Tui\Tui\Core\ScreenContext;
use Phalanx\Tui\Tui\Core\Screen;
use Phalanx\Tui\Tui\Inputs\Key;
use Phalanx\Tui\Tui\Inputs\KeyEvent;
use Phalanx\Tui\Tui\Tdom\Element\TextElement;
use Phalanx\Tui\Tui\Tdom\Renderable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Stub fixtures — never instantiated, exist only for ::class references.

final class ZeusOlympusScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

final class ApolloLogsScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

final class SpartaBattleScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

final class ThermopylaeMainScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

final class SpartaHomeScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

final class ApolloEditorScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

final class SpartaRegistryScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

final class ZeusRegistryScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return new TextElement('');
    }
}

// ---------------------------------------------------------------------------

/**
 * Fixtures use Greek/Spartan lexicon: Zeus, Apollo, Sparta, Thermopylae.
 */
final class BindingRegistryTest extends TestCase
{
    private BindingRegistry $registry;

    // -------------------------------------------------------------------------
    // Global bindings

    #[Test]
    public function globalBindingResolves(): void
    {
        $quit = Binding::ctrl('c')->quit();
        $this->registry->setGlobal([$quit]);

        $match = $this->registry->resolve(new KeyEvent(key: 'c', ctrl: true));

        self::assertSame($quit, $match);
    }

    #[Test]
    public function unresolvedEventReturnsNull(): void
    {
        $this->registry->setGlobal([Binding::ctrl('c')->quit()]);

        $match = $this->registry->resolve(new KeyEvent(key: 'x'));

        self::assertNull($match);
    }

    #[Test]
    public function emptyRegistryReturnsNull(): void
    {
        $match = $this->registry->resolve(new KeyEvent(key: Key::Escape));

        self::assertNull($match);
    }

    // -------------------------------------------------------------------------
    // Screen bindings

    #[Test]
    public function screenBindingResolvesWhenActive(): void
    {
        $save = Binding::key('s')->action(static fn () => null)->label('Save');
        $this->registry->setScreen(SpartaRegistryScreen::class, [$save]);
        $this->registry->activateScreen(SpartaRegistryScreen::class);

        $match = $this->registry->resolve(new KeyEvent(key: 's'));

        self::assertSame($save, $match);
    }

    #[Test]
    public function screenBindingDoesNotResolveWhenNotActive(): void
    {
        $save = Binding::key('s')->action(static fn () => null);
        $this->registry->setScreen(SpartaRegistryScreen::class, [$save]);
        // No activateScreen call.

        $match = $this->registry->resolve(new KeyEvent(key: 's'));

        self::assertNull($match);
    }

    #[Test]
    public function screenBindingOverridesGlobalForSameKey(): void
    {
        $globalQuit = Binding::ctrl('c')->quit()->label('Global quit');
        $screenQuit = Binding::ctrl('c')->quit()->label('Zeus screen quit');

        $this->registry->setGlobal([$globalQuit]);
        $this->registry->setScreen(ZeusOlympusScreen::class, [$screenQuit]);
        $this->registry->activateScreen(ZeusOlympusScreen::class);

        $match = $this->registry->resolve(new KeyEvent(key: 'c', ctrl: true));

        self::assertSame($screenQuit, $match);
        self::assertSame('Zeus screen quit', $match->label);
    }

    #[Test]
    public function globalBindingStillResolvesWhenScreenHasNoMatchForThatKey(): void
    {
        $globalHelp = Binding::key(Key::F1)->quit()->label('Help');
        $screenSave = Binding::key('s')->action(static fn () => null);

        $this->registry->setGlobal([$globalHelp]);
        $this->registry->setScreen(ApolloLogsScreen::class, [$screenSave]);
        $this->registry->activateScreen(ApolloLogsScreen::class);

        $match = $this->registry->resolve(new KeyEvent(key: Key::F1));

        self::assertSame($globalHelp, $match);
    }

    #[Test]
    public function replacingScreenBindingsReplacesSet(): void
    {
        $first = Binding::key('a')->action(static fn () => null);
        $second = Binding::key('b')->action(static fn () => null);

        $this->registry->setScreen(SpartaBattleScreen::class, [$first]);
        $this->registry->setScreen(SpartaBattleScreen::class, [$second]);
        $this->registry->activateScreen(SpartaBattleScreen::class);

        self::assertNull($this->registry->resolve(new KeyEvent(key: 'a')));
        self::assertNotNull($this->registry->resolve(new KeyEvent(key: 'b')));
    }

    // -------------------------------------------------------------------------
    // Overlay stack

    #[Test]
    public function overlayBindingTakesPriorityOverEverything(): void
    {
        $global = Binding::ctrl('c')->quit()->label('global');
        $screen = Binding::ctrl('c')->quit()->label('screen');
        $overlay = Binding::ctrl('c')->quit()->label('overlay');

        $this->registry->setGlobal([$global]);
        $this->registry->setScreen(ThermopylaeMainScreen::class, [$screen]);
        $this->registry->activateScreen(ThermopylaeMainScreen::class);
        $this->registry->pushOverlay('dev-tools', [$overlay]);

        $match = $this->registry->resolve(new KeyEvent(key: 'c', ctrl: true));

        self::assertSame('overlay', $match?->label);
    }

    #[Test]
    public function mostRecentOverlayTakesPriority(): void
    {
        $first = Binding::key(Key::Escape)->quit()->label('first-overlay');
        $second = Binding::key(Key::Escape)->quit()->label('second-overlay');

        $this->registry->pushOverlay('modal-a', [$first]);
        $this->registry->pushOverlay('modal-b', [$second]);

        $match = $this->registry->resolve(new KeyEvent(key: Key::Escape));

        self::assertSame('second-overlay', $match?->label);
    }

    #[Test]
    public function poppingOverlayRestoresPreviousResolution(): void
    {
        $screen = Binding::key(Key::Escape)->quit()->label('screen');
        $overlay = Binding::key(Key::Escape)->quit()->label('overlay');

        $this->registry->setScreen(SpartaHomeScreen::class, [$screen]);
        $this->registry->activateScreen(SpartaHomeScreen::class);
        $this->registry->pushOverlay('modal', [$overlay]);

        // With overlay active.
        self::assertSame('overlay', $this->registry->resolve(new KeyEvent(key: Key::Escape))?->label);

        $this->registry->popOverlay();

        // After pop, screen layer takes over.
        self::assertSame('screen', $this->registry->resolve(new KeyEvent(key: Key::Escape))?->label);
    }

    #[Test]
    public function clearOverlaysRemovesAllLayers(): void
    {
        $overlay = Binding::ctrl('c')->quit()->label('overlay');
        $global = Binding::ctrl('c')->quit()->label('global');

        $this->registry->setGlobal([$global]);
        $this->registry->pushOverlay('a', [$overlay]);
        $this->registry->pushOverlay('b', [$overlay]);

        $this->registry->clearOverlays();

        $match = $this->registry->resolve(new KeyEvent(key: 'c', ctrl: true));
        self::assertSame('global', $match?->label);
    }

    #[Test]
    public function popOnEmptyStackIsNoop(): void
    {
        // Should not throw.
        $this->registry->popOverlay();
        $this->registry->popOverlay();

        self::assertNull($this->registry->resolve(new KeyEvent(key: Key::Enter)));
    }

    #[Test]
    public function overlayBindingForKeyNotInOverlayFallsThroughToScreen(): void
    {
        $screen = Binding::key(Key::F1)->quit()->label('help');
        $overlay = Binding::key(Key::Escape)->quit()->label('dismiss');

        $this->registry->setScreen(ZeusRegistryScreen::class, [$screen]);
        $this->registry->activateScreen(ZeusRegistryScreen::class);
        $this->registry->pushOverlay('help-overlay', [$overlay]);

        // F1 is not in the overlay → should fall through to screen.
        $match = $this->registry->resolve(new KeyEvent(key: Key::F1));
        self::assertSame('help', $match?->label);
    }

    #[Test]
    public function multipleOverlaysWithDifferentKeysResolveIndependently(): void
    {
        $escBinding = Binding::key(Key::Escape)->quit()->label('dismiss-a');
        $f1Binding = Binding::key(Key::F1)->quit()->label('help-b');

        $this->registry->pushOverlay('modal-a', [$escBinding]);
        $this->registry->pushOverlay('modal-b', [$f1Binding]);

        self::assertSame('dismiss-a', $this->registry->resolve(new KeyEvent(key: Key::Escape))?->label);
        self::assertSame('help-b', $this->registry->resolve(new KeyEvent(key: Key::F1))?->label);
    }

    // -------------------------------------------------------------------------
    // activeBindings / shadowing

    #[Test]
    public function activeBindingsReturnsAllVisibleBindings(): void
    {
        $global = Binding::ctrl('q')->quit()->label('Quit');
        $screen = Binding::key('s')->action(static fn () => null)->label('Save');

        $this->registry->setGlobal([$global]);
        $this->registry->setScreen(ApolloEditorScreen::class, [$screen]);
        $this->registry->activateScreen(ApolloEditorScreen::class);

        $active = $this->registry->activeBindings();

        self::assertCount(2, $active);
    }

    #[Test]
    public function activeBindingsShadowsLowerPriorityForSameCombo(): void
    {
        $global = Binding::ctrl('c')->quit()->label('global-quit');
        $screen = Binding::ctrl('c')->quit()->label('screen-quit');

        $this->registry->setGlobal([$global]);
        $this->registry->setScreen(SpartaRegistryScreen::class, [$screen]);
        $this->registry->activateScreen(SpartaRegistryScreen::class);

        $active = $this->registry->activeBindings();

        // Only the screen-level binding should surface for Ctrl+C.
        self::assertCount(1, $active);
        self::assertSame('screen-quit', $active[0]->label);
    }

    #[Test]
    public function activeBindingsIncludesOverlayAndShadowsBelow(): void
    {
        $global = Binding::key(Key::F1)->quit()->label('global-help');
        $overlay = Binding::key(Key::F1)->quit()->label('overlay-help');
        $extra = Binding::ctrl('q')->quit()->label('quit');

        $this->registry->setGlobal([$global, $extra]);
        $this->registry->pushOverlay('help-modal', [$overlay]);

        $active = $this->registry->activeBindings();
        $labels = array_map(static fn (Binding $b) => $b->label, $active);

        self::assertContains('overlay-help', $labels);
        self::assertNotContains('global-help', $labels);
        self::assertContains('quit', $labels);
        self::assertCount(2, $active);
    }

    #[Test]
    public function activeBindingsEmptyWhenNoLayers(): void
    {
        self::assertSame([], $this->registry->activeBindings());
    }

    protected function setUp(): void
    {
        $this->registry = new BindingRegistry();
    }
}
