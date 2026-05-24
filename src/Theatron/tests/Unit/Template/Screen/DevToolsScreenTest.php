<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Template\Screen;

use DateTimeImmutable;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Reactive\SignalRegistry;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Screen\ChatScreen;
use Phalanx\Theatron\Template\Screen\DevToolsScreen;
use Phalanx\Theatron\Template\Screen\LlmRequestDetailScreen;
use Phalanx\Theatron\Template\Slice\ActivitySlice;
use Phalanx\Theatron\Template\Slice\AgentRegistrySlice;
use Phalanx\Theatron\Template\Slice\AgentSummary;
use Phalanx\Theatron\Template\Slice\ConversationSlice;
use Phalanx\Theatron\Template\Slice\DevToolsTab;
use Phalanx\Theatron\Template\Slice\LlmRequestEntry;
use Phalanx\Theatron\Text\Line;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DevToolsScreenTest extends TestCase
{
    #[Test]
    public function defaultMetricsTabRendersRuntimeWithoutLlmRequests(): void
    {
        $store = new AppStore();
        $store->requests = $store->requests->append($this->request('req-1', '/api/chat'));
        $navigator = new RecordingNavigator();
        $screen = new DevToolsScreen($store, new MountSystem($this->createStub(TaskScope::class)), $navigator);

        $result = $screen($this->makeContext($navigator));

        self::assertInstanceOf(ColumnElement::class, $result);

        $text = self::flatten($result);
        self::assertStringContainsString('DevTools', $text);
        self::assertStringContainsString('Metrics', $text);
        self::assertStringContainsString('Requests', $text);
        self::assertStringContainsString('Signals', $text);
        self::assertStringContainsString('Tree', $text);
        self::assertStringContainsString('Store Slices', $text);
        self::assertStringContainsString('Memory', $text);
        self::assertStringContainsString('zmm used', $text);
        self::assertStringContainsString('real used', $text);
        self::assertStringContainsString('Runtime', $text);
        self::assertStringNotContainsString('LLM Requests', $text);
        self::assertStringNotContainsString('POST /api/chat', $text);
    }

    #[Test]
    public function requestsTabRendersLlmRequests(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab();
        $store->requests = $store->requests->append($this->request('req-1', '/api/chat'));
        $navigator = new RecordingNavigator();
        $screen = new DevToolsScreen($store, new MountSystem($this->createStub(TaskScope::class)), $navigator);

        $text = self::flatten($screen($this->makeContext($navigator)));

        self::assertStringContainsString('LLM Requests', $text);
        self::assertStringContainsString('POST /api/chat', $text);
    }

    #[Test]
    public function keyboardNavigationChangesTabs(): void
    {
        $store = new AppStore();
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
        );

        self::assertSame(DevToolsTab::Metrics, $store->devtools->activeTab);

        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Right)));
        self::assertSame(DevToolsTab::Requests, $store->devtools->activeTab);

        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Left)));
        self::assertSame(DevToolsTab::Metrics, $store->devtools->activeTab);
    }

    #[Test]
    public function requestNavigationAndEnterOpenDetailPage(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab();
        $store->requests = $store->requests
            ->append($this->request('req-1', '/api/first'))
            ->append($this->request('req-2', '/api/second'));
        $navigator = new RecordingNavigator();
        $screen = new DevToolsScreen($store, new MountSystem($this->createStub(TaskScope::class)), $navigator);

        self::assertSame(1, $store->requests->focusedIndex);

        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Up)));
        self::assertSame(0, $store->requests->focusedIndex);

        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Enter)));
        self::assertSame(LlmRequestDetailScreen::class, $navigator->lastScreen);
        self::assertSame(0, $store->requests->detailScrollOffset);
        self::assertSame('req-1', $store->requests->selectedRequestId);
    }

    #[Test]
    public function requestDetailSelectionStaysPinnedWhenNewRequestsArrive(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab();
        $store->requests = $store->requests
            ->append($this->request('req-1', '/api/first'))
            ->append($this->request('req-2', '/api/second'));
        $navigator = new RecordingNavigator();
        $screen = new DevToolsScreen($store, new MountSystem($this->createStub(TaskScope::class)), $navigator);

        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Up)));
        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Enter)));

        $store->requests = $store->requests->append($this->request('req-3', '/api/third'));

        self::assertSame(2, $store->requests->focusedIndex);
        self::assertSame('/api/third', $store->requests->focused()?->path);
        self::assertSame('/api/first', $store->requests->selected()?->path);
    }

    #[Test]
    public function requestNavigationIsIgnoredOnMetricsTab(): void
    {
        $store = new AppStore();
        $store->requests = $store->requests
            ->append($this->request('req-1', '/api/first'))
            ->append($this->request('req-2', '/api/second'));
        $navigator = new RecordingNavigator();
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            $navigator,
        );

        self::assertSame(DevToolsTab::Metrics, $store->devtools->activeTab);
        self::assertFalse($screen->handleNormalKey(new KeyEvent(Key::Up)));
        self::assertFalse($screen->handleNormalKey(new KeyEvent(Key::Enter)));
        self::assertSame(1, $store->requests->focusedIndex);
        self::assertNull($navigator->lastScreen);
    }

    #[Test]
    public function requestNavigationIsAvailableOnRequestsTab(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab();
        $store->requests = $store->requests
            ->append($this->request('req-1', '/api/first'))
            ->append($this->request('req-2', '/api/second'));
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
        );

        self::assertSame(DevToolsTab::Requests, $store->devtools->activeTab);
        self::assertTrue($screen->handleNormalKey(new KeyEvent(Key::Up)));
        self::assertSame(0, $store->requests->focusedIndex);
    }

    #[Test]
    public function requestNavigationIsIgnoredOnInspectionTabs(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab()->nextTab();
        $store->requests = $store->requests
            ->append($this->request('req-1', '/api/first'))
            ->append($this->request('req-2', '/api/second'));
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
        );

        self::assertSame(DevToolsTab::Signals, $store->devtools->activeTab);
        self::assertFalse($screen->handleNormalKey(new KeyEvent(Key::Up)));
        self::assertSame(1, $store->requests->focusedIndex);
    }

    #[Test]
    public function liveInspectionTabsOptIntoPeriodicRefresh(): void
    {
        $store = new AppStore();
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
        );

        self::assertSame(0.25, $screen->refreshIntervalSeconds());

        $store->devtools = $store->devtools->nextTab();
        self::assertSame(DevToolsTab::Requests, $store->devtools->activeTab);
        self::assertNull($screen->refreshIntervalSeconds());

        $store->devtools = $store->devtools->nextTab();
        self::assertSame(DevToolsTab::Signals, $store->devtools->activeTab);
        self::assertSame(0.25, $screen->refreshIntervalSeconds());

        $store->devtools = $store->devtools->nextTab();
        self::assertSame(DevToolsTab::Tree, $store->devtools->activeTab);
        self::assertSame(0.25, $screen->refreshIntervalSeconds());

        $store->devtools = $store->devtools->nextTab();
        self::assertSame(DevToolsTab::Store, $store->devtools->activeTab);
        self::assertNull($screen->refreshIntervalSeconds());
    }

    #[Test]
    public function signalsTabShowsEmptyStateWhenRegistryDisabled(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab()->nextTab();
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
        );

        $text = self::flatten($screen($this->makeContext(new RecordingNavigator())));

        self::assertStringContainsString('Signals', $text);
        self::assertStringContainsString('No signals registered', $text);
    }

    #[Test]
    public function signalsTabShowsRegisteredSignals(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab()->nextTab();
        $registry = new SignalRegistry();
        $signal = new Signal(42);
        $registry->register($signal, 'apollo.count');
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
            $registry,
        );

        $text = self::flatten($screen($this->makeContext(new RecordingNavigator())));

        self::assertStringContainsString('apollo.count', $text);
        self::assertStringContainsString('42', $text);
    }

    #[Test]
    public function signalsTabShowsDisposedSignalLabel(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab()->nextTab();
        $registry = new SignalRegistry();
        $signal = new Signal(0);
        $registry->register($signal, 'poseidon.depth');
        $signal->dispose();
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
            $registry,
        );

        $text = self::flatten($screen($this->makeContext(new RecordingNavigator())));

        self::assertStringContainsString('poseidon.depth', $text);
        self::assertStringContainsString('disposed', $text);
    }

    #[Test]
    public function treeTabShowsEmptyStateWhenNoComponents(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab()->nextTab()->nextTab();
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
        );

        $text = self::flatten($screen($this->makeContext(new RecordingNavigator())));

        self::assertStringContainsString('Component Tree', $text);
        self::assertStringContainsString('No components mounted', $text);
    }

    #[Test]
    public function treeTabShowsMountedComponents(): void
    {
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab()->nextTab()->nextTab();
        $scope = $this->createStub(TaskScope::class);
        $mountSystem = new MountSystem($scope);
        $mountSystem->mountComponent(DevToolsTreeFixtureComponent::class);
        $screen = new DevToolsScreen($store, $mountSystem, new RecordingNavigator());

        $text = self::flatten($screen($this->makeContext(new RecordingNavigator(), $mountSystem)));

        self::assertStringContainsString('DevToolsTreeFixtureComponent', $text);
    }

    #[Test]
    public function storeTabShowsSliceInfo(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $store = new AppStore();
        $store->devtools = $store->devtools->nextTab()->nextTab()->nextTab()->nextTab();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('The agora stands.')
            ->appendToken('As does the phalanx.')
            ->appendCue(new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                kind: Kind::FileRead,
                summary: 'Read a strategy note',
            ))
            ->appendCue(new FinalUsage(
                id: 'cue_2',
                sequence: 2,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                inputTokens: 100,
                outputTokens: 200,
                cacheReadTokens: 10,
            ));
        $store->agents = new AgentRegistrySlice()
            ->register(new AgentSummary(id: 'agent_leonidas', name: 'Leonidas', capabilities: ['tactics']));
        $store->activity = new ActivitySlice()->updateUsage(100, 200);
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
        );

        $text = self::flatten($screen($this->makeContext(new RecordingNavigator())));

        self::assertStringContainsString('ConversationSlice', $text);
        self::assertStringContainsString('AgentRegistrySlice', $text);
        self::assertStringContainsString('ActivitySlice', $text);
        self::assertStringContainsString('messages', $text);
        self::assertStringContainsString('turns', $text);
        self::assertStringContainsString('agents', $text);
        self::assertStringContainsString('total', $text);
        self::assertStringContainsString('Conversation Events', $text);
        self::assertStringContainsString('cue_1 effect.requested cue.effect.requested', $text);
        self::assertStringContainsString('Read a strategy note', $text);
        self::assertStringContainsString('cue_2 usage.final cue.usage.final', $text);
        self::assertStringContainsString('cache read 10', $text);
    }

    #[Test]
    public function metricsTabShowsRecentConversationProjectionSummaries(): void
    {
        $at = new DateTimeImmutable('2026-05-23T21:00:00Z');
        $store = new AppStore();
        $store->conversation = (new ConversationSlice())
            ->addUserMessage('Inspect the exchange')
            ->appendToken('Projection visible.')
            ->appendCue(new EffectRequested(
                id: 'cue_1',
                sequence: 1,
                activityId: 'act_1',
                invocationId: 'inv_1',
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                kind: Kind::FileRead,
                summary: 'Read a strategy note',
            ));
        $screen = new DevToolsScreen(
            $store,
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
        );

        $text = self::flatten($screen($this->makeContext(new RecordingNavigator())));

        self::assertStringContainsString('Exchange History', $text);
        self::assertStringContainsString('cue_1', $text);
        self::assertStringContainsString('effect: file.read eff_1', $text);
        self::assertStringContainsString('Read a strategy note', $text);
    }

    #[Test]
    public function statusBarRendersRequestControls(): void
    {
        $screen = new DevToolsScreen(
            new AppStore(),
            new MountSystem($this->createStub(TaskScope::class)),
            new RecordingNavigator(),
        );

        $text = self::flatten($screen->statusBar());

        self::assertStringContainsString('↑ req', $text);
        self::assertStringContainsString('↓ req', $text);
        self::assertStringContainsString('← tab', $text);
        self::assertStringContainsString('→ tab', $text);
        self::assertStringContainsString('Enter detail', $text);
        self::assertStringContainsString('Esc back', $text);
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

    private function request(string $id, string $path): LlmRequestEntry
    {
        return new LlmRequestEntry(
            requestId: $id,
            method: 'POST',
            path: $path,
            status: 200,
            elapsedMs: 39_497.0,
            tokenCount: 312,
            requestBody: '{"model":"qwen3:4b"}',
            responseBody: '{"message":"Strategic Guidance"}',
            complete: true,
        );
    }

    private function makeContext(Navigator $navigator, ?MountSystem $mountSystem = null): ScreenContext
    {
        $scope = $this->createStub(TaskScope::class);
        $mountSystem ??= new MountSystem($scope);

        return new ScreenContext($scope, Theme::default(), $navigator, $mountSystem);
    }
}

final class DevToolsTreeFixtureComponent implements Component
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text('');
    }
}

final class RecordingNavigator implements Navigator
{
    /** @var class-string<Screen>|null */
    public ?string $lastScreen = null;

    /** @param class-string<Screen> $screen */
    public function go(string $screen): void
    {
        $this->lastScreen = $screen;
    }

    public function back(): bool
    {
        return false;
    }

    /** @param class-string<Component> $component */
    public function overlay(string $component, mixed ...$params): void
    {
    }

    public function dismiss(): void
    {
    }

    public function dismissAll(): void
    {
    }

    /** @return class-string<Screen> */
    public function active(): string
    {
        return ChatScreen::class;
    }
}
