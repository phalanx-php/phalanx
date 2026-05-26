<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Ui;

use Closure;
use Phalanx\Harness\Agent\AthenaServiceBundle;
use Phalanx\Harness\Harness;
use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Screens\ChatScreen;
use Phalanx\Harness\Ui\Screens\DevToolsScreen;
use Phalanx\Harness\Ui\Screens\LlmRequestDetailScreen;
use Phalanx\Harness\Ui\Screens\SettingsScreen;
use Phalanx\Harness\Ui\Slices\DevToolsTab;
use Phalanx\Harness\Ui\Slices\LlmRequestEntry;
use Phalanx\Harness\Ui\Slices\SettingsTab;
use Phalanx\Harness\Ui\UiApp;
use Phalanx\Iris\HttpServiceBundle;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Binding\BindingRegistry;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\ModeDispatcher;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Reactive\SignalRegistry;
use Phalanx\Theatron\Stage\ScreenMode;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\TheatronApp;
use Phalanx\Theatron\TheatronBuilder;
use Phalanx\Theatron\TheatronServiceBundle;
use PHPUnit\Framework\Attributes\Test;

final class UiAppReadinessTest extends PhalanxTestCase
{
    private static int $sequenceBindingHits = 0;

    #[Test]
    public function templateAppDrawsFirstFrameWithBinBootstrapShape(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $app = self::templateBuilder($stream)->build();
        $testApp = $this->testApp([
            'PWD' => '/workspace/phalanx',
            'HOME' => '/workspace',
        ], new TheatronServiceBundle($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($app, $stream): void {
            $app->stage->onFrame(static function () use ($scope): void {
                $scope->cancellation()->cancel();
            });

            $app->start($scope);

            rewind($stream);
            $output = self::stripAnsi((string) stream_get_contents($stream));

            self::assertStringContainsString('Type a message to begin.', $output);
            self::assertStringContainsString('+>', $output);
            self::assertStringContainsString('(Λ)', $output);
            self::assertStringContainsString('mem', $output);
            self::assertStringContainsString('alloc', $output);
            self::assertStringContainsString('^X ? keymap', $output);
            self::assertStringNotContainsString('Theatron', $output);
            self::assertStringNotContainsString('Powered by Phalanx PHP', $output);
            self::assertStringNotContainsString('Λ̬', $output);
            self::assertStringNotContainsString('^X d devtools', $output);
            self::assertStringNotContainsString('^X s settings', $output);
            self::assertStringNotContainsString('DevToolsOverlay', $output);
        });
    }

    #[Test]
    public function templateBuilderRegistersExpectedScreensBindingsAndDevtools(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $builder = self::templateBuilder($stream, context: [
            'APP_ENV' => 'test',
            'HARNESS_OLLAMA_MODEL' => 'llama3.1',
        ]);
        $app = $builder->build();

        self::assertSame('llama3.1', $builder->context->get('HARNESS_OLLAMA_MODEL'));
        self::assertSame(UiApp::screens(), $builder->registeredScreens());
        self::assertCount(1, $builder->registeredGlobalBindings());
        self::assertRegisteredProvider($builder->registeredProviders(), HttpServiceBundle::class);
        self::assertRegisteredProvider($builder->registeredProviders(), AthenaServiceBundle::class);
        self::assertSame($app, self::resolvedTheatronBundle($builder->registeredProviders(), $app)->app);
        self::assertSame(AppStore::class, $builder->registeredStore());
        self::assertTrue($app->devtools);
        self::assertInstanceOf(SignalRegistry::class, $app->registry);

        $registry = new BindingRegistry();
        $registry->setGlobal($builder->registeredGlobalBindings());

        self::assertNull($registry->resolve(new KeyEvent('d', ctrl: true)));
        self::assertNull($registry->resolve(new KeyEvent('s', ctrl: true)));
        self::assertNotNull($registry->resolve(new KeyEvent('c', ctrl: true)));
    }

    #[Test]
    public function templateKeySequenceEscapeCancelsPrefixThroughAppDispatch(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $app = self::templateBuilder($stream)->build();
        $testApp = $this->testApp([], new TheatronServiceBundle($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($app, $stream): void {
            $store = $scope->service(AppStore::class);
            $scope->go(static function () use ($app, $scope): void {
                $app->start($scope);
            }, 'theatron-app');
            $scope->delay(0.01);

            self::dispatchInput($app->stage, new KeyEvent('x', ctrl: true));
            $scope->delay(0.01);

            self::assertTrue($store->keySequence->isAwaitingControlX());
            rewind($stream);
            self::assertStringContainsString('^X …', self::stripAnsi((string) stream_get_contents($stream)));

            self::dispatchInput($app->stage, new KeyEvent(Key::Escape));
            $scope->delay(0.01);

            self::assertFalse($store->keySequence->isAwaitingControlX());
            self::assertSame(InputMode::Insert, $store->inputMode->mode);
            self::assertSame('input', $store->inputMode->focusTarget);

            $scope->cancellation()->cancel();
        });
    }

    #[Test]
    public function templateInvalidKeySequenceClearsPrefixBeforeBindings(): void
    {
        self::$sequenceBindingHits = 0;
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $app = self::templateBuilder($stream, [
            Binding::key('z')->action(static function (): void {
                self::$sequenceBindingHits++;
            }),
        ])->build();
        $testApp = $this->testApp([], new TheatronServiceBundle($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($app): void {
            $store = $scope->service(AppStore::class);
            $scope->go(static function () use ($app, $scope): void {
                $app->start($scope);
            }, 'theatron-app');
            $scope->delay(0.01);

            self::dispatchInput($app->stage, new KeyEvent('x', ctrl: true));
            self::assertTrue($store->keySequence->isAwaitingControlX());

            self::dispatchInput($app->stage, new KeyEvent('z'));

            self::assertFalse($store->keySequence->isAwaitingControlX());
            self::assertSame('', $store->input->text);
            self::assertSame(0, self::$sequenceBindingHits);

            $scope->cancellation()->cancel();
        });
    }

    #[Test]
    public function templateNativeControlUStaysSingleStrokeEditorCommandThroughAppDispatch(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $app = self::templateBuilder($stream)->build();
        $testApp = $this->testApp([], new TheatronServiceBundle($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($app): void {
            $store = $scope->service(AppStore::class);
            $scope->go(static function () use ($app, $scope): void {
                $app->start($scope);
            }, 'theatron-app');
            $scope->delay(0.01);

            foreach (['Z', 'e', 'u', 's'] as $key) {
                self::dispatchInput($app->stage, new KeyEvent($key));
            }

            self::assertSame('Zeus', $store->input->text);

            self::dispatchInput($app->stage, new KeyEvent('u', ctrl: true));

            self::assertSame('', $store->input->text);
            self::assertFalse($store->keySequence->isAwaitingControlX());
            self::assertSame(InputMode::Insert, $store->inputMode->mode);
            self::assertSame('input', $store->inputMode->focusTarget);

            $scope->cancellation()->cancel();
        });
    }

    #[Test]
    public function templateQueueKeySequencesRestoreThroughAppDispatch(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $app = self::templateBuilder($stream)->build();
        $testApp = $this->testApp([], new TheatronServiceBundle($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($app): void {
            $store = $scope->service(AppStore::class);
            $store->input = $store->input
                ->enqueue('first queued')
                ->enqueue('second queued');
            $scope->go(static function () use ($app, $scope): void {
                $app->start($scope);
            }, 'theatron-app');
            $scope->delay(0.01);

            self::dispatchInput($app->stage, new KeyEvent('x', ctrl: true));
            self::dispatchInput($app->stage, new KeyEvent('u'));

            self::assertSame('second queued', $store->input->text);
            self::assertSame(['first queued'], $store->input->queue);
            self::assertFalse($store->keySequence->isAwaitingControlX());

            $scope->cancellation()->cancel();
        });
    }

    #[Test]
    public function templateScreensRenderInspectionDetailAndSettingsState(): void
    {
        $store = new AppStore();
        $store->requests = $store->requests->append(new LlmRequestEntry(
            requestId: 'req-1',
            method: 'POST',
            path: '/api/chat',
            status: 200,
            elapsedMs: 39_497.0,
            tokenCount: 312,
            requestBody: '{"model":"qwen3:4b"}',
            responseBody: '{"message":"Strategic Guidance"}',
            complete: true,
        ));

        $scope = $this->createStub(TaskScope::class);
        $mountSystem = new MountSystem($scope, registry: new SignalRegistry());
        $navigator = new TemplateReadinessNavigator();
        $context = new ScreenContext($scope, Theme::default(), $navigator, $mountSystem);

        $devtools = new DevToolsScreen($store, $mountSystem, $navigator, new SignalRegistry());
        $detail = new LlmRequestDetailScreen($store);
        $settings = new SettingsScreen($store);

        $devtoolsText = self::flatten($devtools($context));
        self::assertStringContainsString('Metrics', $devtoolsText);
        self::assertStringContainsString('Requests', $devtoolsText);
        self::assertStringContainsString('Signals', $devtoolsText);
        self::assertStringContainsString('Tree', $devtoolsText);
        self::assertStringContainsString('Store', $devtoolsText);
        self::assertStringNotContainsString('POST /api/chat', $devtoolsText);

        self::assertTrue($devtools->handleNormalKey(new KeyEvent(Key::Right)));
        self::assertSame(DevToolsTab::Requests, $store->devtools->activeTab);
        self::assertStringContainsString('POST /api/chat', self::flatten($devtools($context)));
        self::assertTrue($devtools->handleNormalKey(new KeyEvent(Key::Enter)));
        self::assertSame(LlmRequestDetailScreen::class, $navigator->lastScreen);

        $detailText = self::flatten($detail($context));
        self::assertStringContainsString('POST /api/chat', $detailText);
        self::assertStringContainsString('Request Body', $detailText);
        self::assertStringContainsString('Response Body', $detailText);
        self::assertStringContainsString('Strategic Guidance', $detailText);

        $dispatcher = new ModeDispatcher(new \Phalanx\Theatron\Focus\FocusManager());
        $dispatcher->focus->register('settings', $settings);
        self::assertTrue($dispatcher->dispatch(new KeyEvent(Key::Right)));
        self::assertSame(SettingsTab::Tools, $store->settings->activeTab);

        $settingsText = self::flatten($settings($context));
        self::assertStringContainsString('Settings', $settingsText);
        self::assertStringContainsString('Tools', $settingsText);
    }

    /**
     * @param resource $stream
     * @param list<Binding> $bindings
     * @param array<string,mixed> $context
     */
    private static function templateBuilder(
        mixed $stream,
        array $bindings = [],
        array $context = ['APP_ENV' => 'test'],
    ): TheatronBuilder {
        return Harness::app($context)
            ->globalBindings([...UiApp::bindings(), ...$bindings])
            ->stageConfig(new StageConfig(
                screenMode: ScreenMode::Inline,
                bracketedPaste: false,
                handleInput: false,
                defaultExitHandler: false,
                activeIntervalUs: 1_000,
                stream: $stream,
                env: [
                    'COLUMNS' => '100',
                    'LINES' => '30',
                ],
            ))
            ->devtools();
    }

    private static function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $text);
    }

    private static function dispatchInput(Stage $stage, KeyEvent $event): void
    {
        $method = new \ReflectionMethod($stage, 'dispatchInput');
        $method->invoke($stage, $event);
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

    /**
     * @param list<mixed> $providers
     * @param class-string $type
     */
    private static function assertRegisteredProvider(array $providers, string $type): void
    {
        self::assertNotFalse(array_find(
            $providers,
            static fn(mixed $provider): bool => $provider instanceof $type,
        ));
    }

    /**
     * @param list<mixed> $providers
     */
    private static function resolvedTheatronBundle(array $providers, TheatronApp $app): TheatronServiceBundle
    {
        $closures = array_values(array_filter(
            $providers,
            static fn(mixed $provider): bool => $provider instanceof Closure,
        ));

        self::assertCount(1, $closures);

        $bundle = $closures[0]($app);

        self::assertInstanceOf(TheatronServiceBundle::class, $bundle);
        self::assertInstanceOf(ServiceBundle::class, $bundle);

        return $bundle;
    }
}

final class TemplateReadinessNavigator implements Navigator
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
