<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Apps;

use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Tui\Inputs\Binding;
use Phalanx\Tui\Core\RenderContext;
use Phalanx\Tui\Core\ScreenContext;
use Phalanx\Tui\Core\AcceptsInput;
use Phalanx\Tui\Core\Component;
use Phalanx\Tui\Core\Focusable;
use Phalanx\Tui\Core\HandlesKeySequences;
use Phalanx\Tui\Core\HasActivityPulse;
use Phalanx\Tui\Core\HasFocusables;
use Phalanx\Tui\Core\HasKeySequenceState;
use Phalanx\Tui\Core\HasStatusBar;
use Phalanx\Tui\Core\Screen;
use Phalanx\Tui\Inputs\KeyEvent;
use Phalanx\Tui\Inputs\KeySequenceState;
use Phalanx\Tui\Inputs\NormalModeHandler;
use Phalanx\Tui\Reactive\Signal;
use Phalanx\Tui\Drawing\ScreenMode;
use Phalanx\Tui\Drawing\Stage;
use Phalanx\Tui\Drawing\StageConfig;
use Phalanx\Tui\Reactive\Store;
use Phalanx\Tui\Styles\Theme;
use Phalanx\Tui\Tdom\Renderable;
use Phalanx\Tui\Apps\App;
use Phalanx\Tui\Apps\Bundle;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;

use function Phalanx\Tui\Kit\text;

final class AppInputTest extends PhalanxTestCase
{
    #[Test]
    public function activeInputFocusReceivesCharactersImmediatelyAndRequestsFrame(): void
    {
        InputEchoScreen::$lastInstance = null;

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $stage = Stage::boot(new StageConfig(
            screenMode: ScreenMode::Inline,
            bracketedPaste: false,
            handleInput: false,
            defaultExitHandler: false,
            activeIntervalUs: 1_000,
            stream: $stream,
            env: [
                'COLUMNS' => '20',
                'LINES' => '5',
            ],
        ));
        $app = new App(
            $stage,
            Theme::default(),
            [InputEchoScreen::class],
            [],
            null,
            false,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($app, $stage): void {
            $scope->go(static function () use ($app, $scope): void {
                $app->start($scope);
            }, 'tui-app');
            $scope->delay(Mark::ms(10));

            self::setFrameRequested($stage, false);
            self::dispatchInput($stage, new KeyEvent(key: 'x'));
            self::assertTrue(self::frameRequested($stage));
            $scope->delay(Mark::ms(20));

            $scope->cancellation()->cancel();
        });

        $screen = InputEchoScreen::$lastInstance;
        self::assertInstanceOf(InputEchoScreen::class, $screen);
        self::assertSame('x', $screen->text->get());
        rewind($stream);
        self::assertStringContainsString('x', stream_get_contents($stream));
    }

    #[Test]
    public function overlayNormalHandlerReceivesKeysBeforeGlobalBindings(): void
    {
        OverlayPriorityProbe::$handled = 0;
        OverlayPriorityProbe::$global = 0;

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $stage = Stage::boot(new StageConfig(
            screenMode: ScreenMode::Inline,
            bracketedPaste: false,
            handleInput: false,
            defaultExitHandler: false,
            activeIntervalUs: 1_000,
            stream: $stream,
            env: [
                'COLUMNS' => '20',
                'LINES' => '5',
            ],
        ));
        $app = new App(
            $stage,
            Theme::default(),
            [OverlayPriorityScreen::class],
            [
                Binding::key('o')->toggle(OverlayPriorityProbe::class),
                Binding::key('a')->action(static function (): void {
                    OverlayPriorityProbe::$global++;
                }),
            ],
            null,
            false,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($app, $stage): void {
            $scope->go(static function () use ($app, $scope): void {
                $app->start($scope);
            }, 'tui-app');
            $scope->delay(Mark::ms(10));

            self::dispatchInput($stage, new KeyEvent(key: 'o'));
            self::dispatchInput($stage, new KeyEvent(key: 'a'));

            $scope->cancellation()->cancel();
        });

        self::assertSame(1, OverlayPriorityProbe::$handled);
        self::assertSame(0, OverlayPriorityProbe::$global);
    }

    #[Test]
    public function overlayNormalHandlerReceivesPrefixKeysBeforeScreenKeySequences(): void
    {
        KeySequencePriorityOverlay::$handled = 0;
        KeySequencePriorityScreen::$started = 0;

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $app = new App(
            Stage::boot(new StageConfig(
                screenMode: ScreenMode::Inline,
                bracketedPaste: false,
                handleInput: false,
                defaultExitHandler: false,
                activeIntervalUs: 1_000,
                stream: $stream,
                env: [
                    'COLUMNS' => '20',
                    'LINES' => '5',
                ],
            )),
            Theme::default(),
            [KeySequencePriorityScreen::class],
            [Binding::key('o')->toggle(KeySequencePriorityOverlay::class)],
            KeySequenceProbeStore::class,
            false,
        );
        $testApp = $this->testApp([], new Bundle($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($app): void {
            $store = $scope->service(KeySequenceProbeStore::class);
            $scope->go(static function () use ($app, $scope): void {
                $app->start($scope);
            }, 'tui-app');
            $scope->delay(Mark::ms(10));

            self::dispatchInput($app->stage, new KeyEvent('o'));
            self::dispatchInput($app->stage, new KeyEvent('x', ctrl: true));

            self::assertFalse($store->keySequence->isAwaitingControlX());

            $scope->cancellation()->cancel();
        });

        self::assertSame(1, KeySequencePriorityOverlay::$handled);
        self::assertSame(0, KeySequencePriorityScreen::$started);
    }

    #[Test]
    public function modalOverlayConsumesUnhandledKeysBeforeComposerInput(): void
    {
        InputEchoScreen::$lastInstance = null;
        ModalConsumeProbe::$handled = 0;

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $stage = Stage::boot(new StageConfig(
            screenMode: ScreenMode::Inline,
            bracketedPaste: false,
            handleInput: false,
            defaultExitHandler: false,
            activeIntervalUs: 1_000,
            stream: $stream,
            env: [
                'COLUMNS' => '20',
                'LINES' => '5',
            ],
        ));
        $app = new App(
            $stage,
            Theme::default(),
            [InputEchoScreen::class],
            [Binding::key('o')->toggle(ModalConsumeProbe::class)],
            null,
            false,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($app, $stage): void {
            $scope->go(static function () use ($app, $scope): void {
                $app->start($scope);
            }, 'tui-app');
            $scope->delay(Mark::ms(10));

            self::dispatchInput($stage, new KeyEvent(key: 'o'));
            self::dispatchInput($stage, new KeyEvent(key: 'x'));

            $scope->cancellation()->cancel();
        });

        $screen = InputEchoScreen::$lastInstance;
        self::assertInstanceOf(InputEchoScreen::class, $screen);
        self::assertSame('', $screen->text->get());
        self::assertSame(1, ModalConsumeProbe::$handled);
    }

    #[Test]
    public function overlayStatusBarReplacesScreenStatusBar(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $stage = Stage::boot(new StageConfig(
            screenMode: ScreenMode::Inline,
            bracketedPaste: false,
            handleInput: false,
            defaultExitHandler: false,
            activeIntervalUs: 1_000,
            stream: $stream,
            env: [
                'COLUMNS' => '30',
                'LINES' => '5',
            ],
        ));
        $app = new App(
            $stage,
            Theme::default(),
            [OverlayStatusScreen::class],
            [Binding::key('o')->toggle(OverlayStatusProbe::class)],
            null,
            false,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($app, $stage, $stream): void {
            $scope->go(static function () use ($app, $scope): void {
                $app->start($scope);
            }, 'tui-app');
            $scope->delay(Mark::ms(10));

            self::dispatchInput($stage, new KeyEvent(key: 'o'));
            $scope->delay(Mark::ms(20));

            $scope->cancellation()->cancel();
            rewind($stream);
            $output = stream_get_contents($stream);
            self::assertStringContainsString('overlay-status', $output);
            $finalStatus = substr($output, strrpos($output, 'overlay-status') ?: 0);
            self::assertStringNotContainsString('screen-status', $finalStatus);
        });
    }

    #[Test]
    public function runningActivityPulseTicksDuringDrawLoop(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $app = new App(
            Stage::boot(new StageConfig(
                screenMode: ScreenMode::Inline,
                bracketedPaste: false,
                handleInput: false,
                defaultExitHandler: false,
                activeIntervalUs: 1_000,
                stream: $stream,
                env: [
                    'COLUMNS' => '20',
                    'LINES' => '5',
                ],
            )),
            Theme::default(),
            [PulseScreen::class],
            [],
            PulseStore::class,
            false,
        );
        $testApp = $this->testApp([], new Bundle($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($app): void {
            $store = $scope->service(PulseStore::class);
            $store->pulse = new PulseSlice(isBusy: true);
            $app->stage->onFrame(static function () use ($scope, $store): void {
                if ($store->pulse->frame > 0) {
                    $scope->cancellation()->cancel();
                }
            });

            $app->start($scope);

            self::assertGreaterThan(0, $store->pulse->frame);
        });
    }

    private static function dispatchInput(Stage $stage, KeyEvent $event): void
    {
        $method = new ReflectionMethod($stage, 'dispatchInput');
        $method->invoke($stage, $event);
    }

    private static function frameRequested(Stage $stage): bool
    {
        $property = new ReflectionProperty($stage, 'frameRequested');

        return $property->getValue($stage);
    }

    private static function setFrameRequested(Stage $stage, bool $value): void
    {
        $property = new ReflectionProperty($stage, 'frameRequested');
        $property->setValue($stage, $value);
    }
}

final class InputEchoScreen implements Screen, HasFocusables
{
    public static ?self $lastInstance = null;

    private(set) Signal $text;

    public function __construct()
    {
        self::$lastInstance = $this;
        $this->text = new Signal('');
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        return text($this->text->get());
    }

    /** @return list<array{string, Focusable}> */
    public function focusables(): array
    {
        return [['input', new InputEchoHandler($this)]];
    }
}

final class InputEchoHandler implements Focusable, AcceptsInput
{
    public function __construct(private InputEchoScreen $screen)
    {
    }

    public function handleInput(KeyEvent $event): bool
    {
        $char = $event->char();

        if ($char === null) {
            return false;
        }

        $this->screen->text->update(static fn(string $text): string => $text . $char);

        return true;
    }
}

final class KeySequenceProbeStore extends Store implements HasKeySequenceState
{
    public KeySequenceState $keySequence {
        get => $this->read(KeySequenceState::class);
        set {
            $this->write(KeySequenceState::class, $value);
        }
    }

    public function __construct()
    {
        $this->register(KeySequenceState::class, new KeySequenceState());
    }

    public function keySequenceState(): KeySequenceState
    {
        return $this->keySequence;
    }

    public function updateKeySequence(KeySequenceState $state): void
    {
        $this->keySequence = $state;
    }
}

final class PulseStore extends Store implements HasActivityPulse
{
    public PulseSlice $pulse {
        get => $this->read(PulseSlice::class);
        set {
            $this->write(PulseSlice::class, $value);
        }
    }

    public function __construct()
    {
        $this->register(PulseSlice::class, new PulseSlice());
    }

    public function activityIsBusy(): bool
    {
        return $this->pulse->isBusy;
    }

    public function tickActivity(): void
    {
        $this->pulse = new PulseSlice(
            isBusy: $this->pulse->isBusy,
            frame: $this->pulse->frame + 1,
        );
    }
}

final class PulseSlice
{
    public function __construct(
        private(set) bool $isBusy = false,
        private(set) int $frame = 0,
    ) {
    }
}

final class PulseScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return text('pulse');
    }
}

final class OverlayPriorityScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return text('screen');
    }
}

final class OverlayPriorityProbe implements Component, NormalModeHandler
{
    public static int $handled = 0;

    public static int $global = 0;

    public function __invoke(RenderContext $ctx): Renderable
    {
        return text('overlay');
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        if (!$event->is('a')) {
            return false;
        }

        self::$handled++;

        return true;
    }
}

final class KeySequencePriorityScreen implements Screen, HandlesKeySequences
{
    public static int $started = 0;

    public function __invoke(ScreenContext $ctx): Renderable
    {
        return text('screen');
    }

    public function startsKeySequence(KeyEvent $event): bool
    {
        if (!$event->is('x') || !$event->ctrl) {
            return false;
        }

        self::$started++;

        return true;
    }

    public function handleKeySequence(KeySequenceState $state, KeyEvent $event): bool
    {
        return $state->isAwaitingControlX();
    }
}

final class KeySequencePriorityOverlay implements Component, NormalModeHandler
{
    public static int $handled = 0;

    public function __invoke(RenderContext $ctx): Renderable
    {
        return text('overlay');
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        if (!$event->is('x') || !$event->ctrl) {
            return false;
        }

        self::$handled++;

        return true;
    }
}

final class OverlayStatusScreen implements Screen, HasStatusBar
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return text('screen');
    }

    public function statusBar(): Renderable
    {
        return text('screen-status');
    }
}

final class OverlayStatusProbe implements Component, HasStatusBar
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return text('overlay');
    }

    public function statusBar(): Renderable
    {
        return text('overlay-status');
    }
}

final class ModalConsumeProbe implements Component, NormalModeHandler
{
    public static int $handled = 0;

    public function __invoke(RenderContext $ctx): Renderable
    {
        return text('overlay');
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        self::$handled++;

        return true;
    }
}
