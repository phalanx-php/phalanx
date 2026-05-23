<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Screen;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Screen\AgentBoardScreen;
use Phalanx\Theatron\Template\Slice\AgentRegistrySlice;
use Phalanx\Theatron\Template\Slice\AgentSummary;
use Phalanx\Theatron\Text\Line;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentBoardScreenTest extends TestCase
{
    #[Test]
    public function renderEmptyAgents(): void
    {
        $store = new AppStore();
        $screen = new AgentBoardScreen($store);
        $ctx = $this->makeContext($store);

        $result = $screen($ctx);

        self::assertInstanceOf(ColumnElement::class, $result);
        self::assertCount(1, $result->children);

        $cardArea = $result->children[0];
        self::assertInstanceOf(TextElement::class, $cardArea);
        self::assertInstanceOf(Line::class, $cardArea->content);

        $combined = implode('', array_map(
            static fn ($s) => $s->content,
            $cardArea->content->spans,
        ));
        self::assertStringContainsString('No agents registered', $combined);
    }

    #[Test]
    public function renderWithAgents(): void
    {
        $store = new AppStore();
        $store->agents = new AgentRegistrySlice()
            ->register(new AgentSummary(id: 'agent_zeus', name: 'Zeus', capabilities: ['reasoning']))
            ->register(new AgentSummary(id: 'agent_apollo', name: 'Apollo', capabilities: ['code_gen']));

        $screen = new AgentBoardScreen($store);
        $ctx = $this->makeContext($store);

        $result = $screen($ctx);

        self::assertInstanceOf(ColumnElement::class, $result);
        self::assertCount(1, $result->children);

        $cardArea = $result->children[0];
        self::assertInstanceOf(RowElement::class, $cardArea);
        self::assertCount(2, $cardArea->children);

        self::assertInstanceOf(PanelElement::class, $cardArea->children[0]);
        self::assertInstanceOf(PanelElement::class, $cardArea->children[1]);
        self::assertSame('Zeus', $cardArea->children[0]->title);
        self::assertSame('Apollo', $cardArea->children[1]->title);
    }

    #[Test]
    public function renderHighlightsActiveAgent(): void
    {
        $store = new AppStore();
        $store->agents = new AgentRegistrySlice()
            ->register(new AgentSummary(id: 'agent_zeus', name: 'Zeus', capabilities: []))
            ->register(new AgentSummary(id: 'agent_apollo', name: 'Apollo', capabilities: []))
            ->activate('agent_zeus');

        $screen = new AgentBoardScreen($store);
        $ctx = $this->makeContext($store);

        $result = $screen($ctx);

        self::assertInstanceOf(ColumnElement::class, $result);
        $cardArea = $result->children[0];
        self::assertInstanceOf(RowElement::class, $cardArea);

        $zeusPanel = $cardArea->children[0];
        self::assertInstanceOf(PanelElement::class, $zeusPanel);

        $zeusBody = $zeusPanel->child;
        self::assertInstanceOf(ColumnElement::class, $zeusBody);

        $combinedZeus = self::flattenTextChildren($zeusBody->children);
        self::assertStringContainsString('Active', $combinedZeus);

        $apolloPanel = $cardArea->children[1];
        self::assertInstanceOf(PanelElement::class, $apolloPanel);

        $apolloBody = $apolloPanel->child;
        self::assertInstanceOf(ColumnElement::class, $apolloBody);

        self::assertStringNotContainsString('Active', self::flattenTextChildren($apolloBody->children));
    }

    #[Test]
    public function statusBarRenders(): void
    {
        $store = new AppStore();
        $store->agents = new AgentRegistrySlice()
            ->register(new AgentSummary(id: 'agent_zeus', name: 'Zeus', capabilities: []))
            ->register(new AgentSummary(id: 'agent_apollo', name: 'Apollo', capabilities: []));

        $screen = new AgentBoardScreen($store);
        $result = $screen->statusBar();

        self::assertInstanceOf(StatusLineElement::class, $result);
        self::assertNotEmpty($result->sections);

        $combined = implode('', array_map(
            static fn ($el) => $el instanceof TextElement && is_string($el->content) ? $el->content : '',
            $result->sections,
        ));
        self::assertStringContainsString('2', $combined);
    }

    #[Test]
    public function focusablesReturnsSelf(): void
    {
        $store = new AppStore();
        $screen = new AgentBoardScreen($store);

        $focusables = $screen->focusables();

        self::assertCount(1, $focusables);
        self::assertSame('agents', $focusables[0][0]);
        self::assertSame($screen, $focusables[0][1]);
    }

    // ---- NormalModeHandler / navigation tests ----

    #[Test]
    public function navigateDown(): void
    {
        $store = new AppStore();
        $store->agents = new AgentRegistrySlice()
            ->register(new AgentSummary(id: 'agent_zeus', name: 'Zeus', capabilities: []))
            ->register(new AgentSummary(id: 'agent_apollo', name: 'Apollo', capabilities: []));

        $screen = new AgentBoardScreen($store);
        $indexProp = new \ReflectionProperty(AgentBoardScreen::class, 'selectedIndex');

        self::assertSame(0, $indexProp->getValue($screen));

        $result = $screen->handleNormalKey(new KeyEvent('j'));
        self::assertTrue($result);
        self::assertSame(1, $indexProp->getValue($screen));

        // Already at last item — clamped at count - 1.
        $screen->handleNormalKey(new KeyEvent('j'));
        self::assertSame(1, $indexProp->getValue($screen));

        // Key::Down is equivalent to 'j'.
        $indexProp->setValue($screen, 0);
        $screen->handleNormalKey(new KeyEvent(Key::Down));
        self::assertSame(1, $indexProp->getValue($screen));
    }

    #[Test]
    public function navigateUp(): void
    {
        $store = new AppStore();
        $store->agents = new AgentRegistrySlice()
            ->register(new AgentSummary(id: 'agent_zeus', name: 'Zeus', capabilities: []))
            ->register(new AgentSummary(id: 'agent_apollo', name: 'Apollo', capabilities: []));

        $screen = new AgentBoardScreen($store);
        $indexProp = new \ReflectionProperty(AgentBoardScreen::class, 'selectedIndex');
        $indexProp->setValue($screen, 1);

        $result = $screen->handleNormalKey(new KeyEvent('k'));
        self::assertTrue($result);
        self::assertSame(0, $indexProp->getValue($screen));

        // Already at first item — clamped at 0.
        $screen->handleNormalKey(new KeyEvent('k'));
        self::assertSame(0, $indexProp->getValue($screen));

        // Key::Up is equivalent to 'k'.
        $indexProp->setValue($screen, 1);
        $screen->handleNormalKey(new KeyEvent(Key::Up));
        self::assertSame(0, $indexProp->getValue($screen));
    }

    #[Test]
    public function navigateEmptyList(): void
    {
        $store = new AppStore();
        $screen = new AgentBoardScreen($store);

        // j/k on an empty agent list must return false — nothing to select.
        self::assertFalse($screen->handleNormalKey(new KeyEvent('j')));
        self::assertFalse($screen->handleNormalKey(new KeyEvent('k')));
        self::assertFalse($screen->handleNormalKey(new KeyEvent(Key::Down)));
        self::assertFalse($screen->handleNormalKey(new KeyEvent(Key::Up)));
    }

    // ---- helpers ----

    /** @param list<\Phalanx\Theatron\Tdom\Renderable> $children */
    private static function flattenTextChildren(array $children): string
    {
        return implode('', array_map(
            static function ($el): string {
                if (!$el instanceof TextElement) {
                    return '';
                }

                if (is_string($el->content)) {
                    return $el->content;
                }

                return implode('', array_map(static fn ($s) => $s->content, $el->content->spans));
            },
            $children,
        ));
    }

    private function makeContext(AppStore $store): ScreenContext
    {
        $scope = $this->createStub(TaskScope::class);
        $navigator = $this->createStub(Navigator::class);
        $mountSystem = new MountSystem($scope);

        return new ScreenContext($scope, Theme::default(), $navigator, $mountSystem);
    }
}
