<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Ui\Screens;

use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Screens\SettingsScreen;
use Phalanx\Harness\Ui\Slices\ActivitySlice;
use Phalanx\Harness\Ui\Slices\SettingsTab;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\DeclaresBindings;
use Phalanx\Theatron\Contract\HasFocusables;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Text\Line;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsScreenTest extends TestCase
{
    #[Test]
    public function rendersSettingsPageWithTabsAndGeneralControls(): void
    {
        $screen = new SettingsScreen(new AppStore());
        $result = $screen($this->makeContext());

        self::assertInstanceOf(ColumnElement::class, $result);

        $text = self::flatten($result);
        self::assertStringContainsString('Settings', $text);
        self::assertStringContainsString('General', $text);
        self::assertStringContainsString('Tools', $text);
        self::assertStringContainsString('MCP', $text);
        self::assertStringContainsString('[ ] Line numbers in code blocks', $text);
        self::assertStringContainsString('[x] Syntax highlighting', $text);
        self::assertStringContainsString('[x] Compact history panels', $text);
    }

    #[Test]
    public function implementsPageInteractionContracts(): void
    {
        $screen = new SettingsScreen(new AppStore());

        self::assertInstanceOf(HasStatusBar::class, $screen);
        self::assertInstanceOf(HasFocusables::class, $screen);
        self::assertInstanceOf(DeclaresBindings::class, $screen);
        self::assertSame('settings', $screen->focusables()[0][0]);
    }

    #[Test]
    public function statusBarRendersSettingsControls(): void
    {
        $screen = new SettingsScreen(new AppStore());

        $text = self::flatten($screen->statusBar());

        self::assertStringContainsString('← tab', $text);
        self::assertStringContainsString('→ tab', $text);
        self::assertStringContainsString('↑/↓ item', $text);
        self::assertStringContainsString('Space toggle', $text);
        self::assertStringContainsString('Esc back', $text);
    }

    #[Test]
    public function keyboardNavigationChangesTabsItemsAndToggles(): void
    {
        $store = new AppStore();
        $screen = new SettingsScreen($store);

        self::assertSame(SettingsTab::General, $store->settings->activeTab);

        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Right)));
        self::assertSame(SettingsTab::Tools, $store->settings->activeTab);

        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Down)));
        self::assertSame(1, $store->settings->selectedItem);

        self::assertFalse($store->settings->isEnabled(SettingsTab::Tools, 1));
        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Space)));
        self::assertTrue($store->settings->isEnabled(SettingsTab::Tools, 1));
    }

    #[Test]
    public function modelTabReflectsActivityModelName(): void
    {
        $store = new AppStore();
        $store->activity = new ActivitySlice()->withModelName('qwen3:4b');
        $store->settings = $store->settings->nextTab()->nextTab()->nextTab();
        $screen = new SettingsScreen($store);

        $text = self::flatten($screen($this->makeContext()));

        self::assertStringContainsString('Model: qwen3:4b', $text);
    }

    private static function flatten(Renderable|string $renderable): string
    {
        if (is_string($renderable)) {
            return $renderable;
        }

        if ($renderable instanceof TextElement) {
            return self::lineToText($renderable->content);
        }

        if ($renderable instanceof InputElement) {
            return self::lineToText($renderable->prompt) . $renderable->value;
        }

        if ($renderable instanceof ColumnElement || $renderable instanceof RowElement) {
            return implode("\n", array_map(self::flatten(...), $renderable->children));
        }

        if ($renderable instanceof PanelElement) {
            return self::lineToText($renderable->title) . "\n" . self::flatten($renderable->child);
        }

        return '';
    }

    private static function lineToText(string|Line $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return implode('', array_map(static fn($span): string => $span->content, $content->spans));
    }

    private function makeContext(): ScreenContext
    {
        $scope = $this->createStub(TaskScope::class);
        $navigator = $this->createStub(Navigator::class);
        $mountSystem = new MountSystem($scope);

        return new ScreenContext($scope, Theme::default(), $navigator, $mountSystem);
    }
}
