<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Overlay;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Template\Keymap\ComposerChordMap;
use Phalanx\Theatron\Template\Keymap\TemplateKeymap;
use Phalanx\Theatron\Template\Overlay\KeymapOverlay;
use Phalanx\Theatron\Template\Screen\ChatScreen;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeymapOverlayTest extends TestCase
{
    #[Test]
    public function rendersCuratedComposerQueueAppAndOverlayShortcuts(): void
    {
        $overlay = new KeymapOverlay(new KeymapNavigator());

        $result = $overlay($this->makeContext());

        self::assertInstanceOf(PanelElement::class, $result);
        self::assertSame('Keymap', $result->title);

        $text = self::flatten($result);
        self::assertStringContainsString('Composer', $text);
        self::assertStringContainsString('Queue', $text);
        self::assertStringContainsString('Blocks', $text);
        self::assertStringContainsString('DevTools', $text);
        self::assertStringContainsString('Settings', $text);
        self::assertStringContainsString('Detail', $text);
        self::assertStringContainsString('Agent Board', $text);
        self::assertStringContainsString('Effect Approval', $text);
        self::assertStringContainsString('App', $text);
        self::assertStringContainsString('Overlay', $text);
        self::assertStringContainsString('^X ?', $text);
        self::assertStringContainsString('keymap', $text);
        self::assertStringContainsString('^X a', $text);
        self::assertStringContainsString('undo all', $text);
        self::assertStringContainsString('Ctrl+A/E', $text);
        self::assertStringContainsString('line start/end', $text);
        self::assertStringContainsString('Ctrl+U', $text);
        self::assertStringContainsString('clear before cursor', $text);
        self::assertStringContainsString('Left/Right', $text);
        self::assertStringContainsString('switch tab', $text);
        self::assertStringContainsString('Space', $text);
        self::assertStringContainsString('toggle item', $text);
        self::assertStringContainsString('A', $text);
        self::assertStringContainsString('approve effect', $text);
        self::assertStringContainsString('Ctrl+C', $text);
        self::assertStringContainsString('quit', $text);
        self::assertStringContainsString('Esc', $text);
        self::assertStringContainsString('close overlay', $text);
    }

    #[Test]
    public function templateKeymapContainsEveryComposerChordExactlyOnce(): void
    {
        $entries = TemplateKeymap::entries();

        foreach (ComposerChordMap::entries() as $chord) {
            $matches = array_filter(
                $entries,
                static fn($entry): bool => $entry->combo === $chord->combo
                    && $entry->label === $chord->label
                    && $entry->section === $chord->section,
            );

            self::assertCount(1, $matches, $chord->combo);
        }
    }

    #[Test]
    public function templateKeymapDoesNotDuplicateRows(): void
    {
        $keys = array_map(
            static fn($entry): string => $entry->section . '|' . $entry->combo . '|' . $entry->label,
            TemplateKeymap::entries(),
        );

        self::assertSame($keys, array_values(array_unique($keys)));
    }

    #[Test]
    public function escapeAndQDismiss(): void
    {
        $navigator = new KeymapNavigator();
        $overlay = new KeymapOverlay($navigator);

        self::assertTrue($overlay->handleNormalKey(new KeyEvent(Key::Escape)));
        self::assertTrue($overlay->handleNormalKey(new KeyEvent('q')));
        self::assertSame(1, $navigator->dismissals);
    }

    #[Test]
    public function ordinaryKeysAreConsumedSoTheyCannotReachComposer(): void
    {
        $navigator = new KeymapNavigator();
        $overlay = new KeymapOverlay($navigator);

        self::assertTrue($overlay->handleNormalKey(new KeyEvent('x', ctrl: true)));
        self::assertTrue($overlay->handleNormalKey(new KeyEvent('?')));
        self::assertSame(0, $navigator->dismissals);
    }

    #[Test]
    public function statusBarShowsCloseControls(): void
    {
        $overlay = new KeymapOverlay(new KeymapNavigator());

        self::assertStringContainsString('Esc', self::flatten($overlay->statusBar()));
        self::assertStringContainsString('q', self::flatten($overlay->statusBar()));
        self::assertStringContainsString('close', self::flatten($overlay->statusBar()));
    }

    #[Test]
    public function mountSystemInjectsNavigatorForKeymapOverlay(): void
    {
        $scope = $this->createStub(TaskScope::class);
        $navigator = new KeymapNavigator();
        $mountSystem = new MountSystem($scope);
        $mountSystem->provide(Navigator::class, $navigator);

        $mounted = $mountSystem->mountComponent(KeymapOverlay::class);
        $rendered = $mounted->render(new RenderContext($scope, Theme::default(), $mountSystem));

        self::assertInstanceOf(KeymapOverlay::class, $mounted->component);
        self::assertStringContainsString('^X ?', self::flatten($rendered));
        self::assertStringContainsString('close overlay', self::flatten($rendered));
    }

    private static function flatten(Renderable $renderable): string
    {
        if ($renderable instanceof TextElement) {
            if (is_string($renderable->content)) {
                return strip_tags($renderable->content);
            }

            return implode('', array_map(
                static fn($span): string => $span->content,
                $renderable->content->spans,
            ));
        }

        if ($renderable instanceof PanelElement) {
            return self::flatten($renderable->child);
        }

        if ($renderable instanceof ColumnElement) {
            return implode(' ', array_map(self::flatten(...), $renderable->children));
        }

        return '';
    }

    private function makeContext(): RenderContext
    {
        $scope = $this->createStub(TaskScope::class);

        return new RenderContext($scope, Theme::default(), new MountSystem($scope));
    }
}

final class KeymapNavigator implements Navigator
{
    public int $dismissals = 0;

    public function go(string $screen): void
    {
    }

    public function back(): bool
    {
        return false;
    }

    public function overlay(string $component, mixed ...$params): void
    {
    }

    public function dismiss(): void
    {
        $this->dismissals++;
    }

    public function dismissAll(): void
    {
    }

    public function active(): string
    {
        return ChatScreen::class;
    }
}
