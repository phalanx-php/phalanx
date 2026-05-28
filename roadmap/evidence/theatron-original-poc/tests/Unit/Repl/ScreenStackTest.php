<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Input\HotkeyBinding;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyRegistry;
use Phalanx\Theatron\Demos\Repl\Screen\Screen;
use Phalanx\Theatron\Demos\Repl\Screen\ScreenStack;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenStackTest extends TestCase
{
    #[Test]
    public function is_top_overlay_returns_false_when_empty(): void
    {
        $stack = new ScreenStack(new HotkeyRegistry());

        self::assertFalse($stack->isTopOverlay());
    }

    #[Test]
    public function is_top_overlay_returns_false_for_non_overlay_screen(): void
    {
        $stack = new ScreenStack(new HotkeyRegistry());
        $stack->register(self::stubScreen('base', false));
        $stack->push('base');

        self::assertFalse($stack->isTopOverlay());
    }

    #[Test]
    public function is_top_overlay_returns_true_for_overlay_screen(): void
    {
        $stack = new ScreenStack(new HotkeyRegistry());
        $stack->register(self::stubScreen('base', false));
        $stack->register(self::stubScreen('overlay', true));
        $stack->push('base');
        $stack->push('overlay');

        self::assertTrue($stack->isTopOverlay());
    }

    #[Test]
    public function is_top_overlay_tracks_top_of_stack(): void
    {
        $stack = new ScreenStack(new HotkeyRegistry());
        $stack->register(self::stubScreen('base', false));
        $stack->register(self::stubScreen('overlay', true));
        $stack->push('base');
        $stack->push('overlay');

        self::assertTrue($stack->isTopOverlay());

        $stack->pop();

        self::assertFalse($stack->isTopOverlay());
    }

    #[Test]
    public function is_top_overlay_returns_false_when_overlay_is_not_top(): void
    {
        $stack = new ScreenStack(new HotkeyRegistry());
        $stack->register(self::stubScreen('overlay', true));
        $stack->register(self::stubScreen('detail', false));
        $stack->push('overlay');
        $stack->push('detail');

        self::assertFalse($stack->isTopOverlay());
    }

    private static function stubScreen(string $name, bool $overlay): Screen
    {
        return new class ($name, $overlay) implements Screen {
            public string $name { get => $this->screenName; }

            public function __construct(
                private(set) string $screenName,
                private(set) bool $overlay,
            ) {
            }

            public function isOverlay(): bool
            {
                return $this->overlay;
            }

            /** @return list<HotkeyBinding> */
            public function bindings(): array
            {
                return [];
            }

            public function handleInput(KeyEvent $event, HotkeyContext $ctx): bool
            {
                return false;
            }

            public function render(Ui $ui, HotkeyContext $ctx, int $width, int $height): Renderable
            {
                return $ui->text(Line::from(Span::plain('')));
            }
        };
    }
}
