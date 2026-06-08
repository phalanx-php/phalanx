<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Apps;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Stream;
use Phalanx\Supervisor\TaskRunSnapshot;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Tui\Core\ScreenContext;
use Phalanx\Tui\Core\Screen;
use Phalanx\Tui\Drawing\ScreenMode;
use Phalanx\Tui\Drawing\Stage;
use Phalanx\Tui\Drawing\StageConfig;
use Phalanx\Tui\Reactive\Store;
use Phalanx\Tui\Styles\Theme;
use Phalanx\Tui\Tdom\Renderable;
use Phalanx\Tui\Apps\App;
use Phalanx\Tui\Apps\Bundle;
use PHPUnit\Framework\Attributes\Test;

final class AppRenderDiagnosticsTest extends PhalanxTestCase
{
    #[Test]
    public function appDrawLoopUsesNamedRenderDiagnosticTaskForActiveScreen(): void
    {
        AppRenderDiagnosticsProbeScreen::$renderRun = null;

        $stream = Stream::memoryBuffer();

        $app = new App(
            Stage::boot(new StageConfig(
                screenMode: ScreenMode::Inline,
                bracketedPaste: false,
                handleInput: false,
                defaultExitHandler: false,
                activeIntervalUs: 1_000,
                stream: $stream->resource(),
                env: [
                    'COLUMNS' => '20',
                    'LINES' => '5',
                ],
            )),
            Theme::default(),
            [AppRenderDiagnosticsProbeScreen::class],
            [],
            null,
            false,
        );

        $this->scope->run(static function (ExecutionScope $scope) use ($app): void {
            $app->start($scope);
        });

        self::assertInstanceOf(TaskRunSnapshot::class, AppRenderDiagnosticsProbeScreen::$renderRun);
        self::assertSame(
            'tui.render.screen ' . AppRenderDiagnosticsProbeScreen::class,
            AppRenderDiagnosticsProbeScreen::$renderRun->name,
        );
        self::assertNull(AppRenderDiagnosticsProbeScreen::$renderRun->currentWait);
    }

    #[Test]
    public function appUsesConfiguredRuntimeStoreInstance(): void
    {
        StoreInstanceProbeScreen::$renderedStoreId = null;

        $stream = Stream::memoryBuffer();

        $stageConfig = new StageConfig(
            screenMode: ScreenMode::Inline,
            bracketedPaste: false,
            handleInput: false,
            defaultExitHandler: false,
            activeIntervalUs: 1_000,
            stream: $stream->resource(),
            env: [
                'COLUMNS' => '20',
                'LINES' => '5',
            ],
        );
        $app = new App(
            Stage::boot($stageConfig),
            Theme::default(),
            [StoreInstanceProbeScreen::class],
            [],
            StoreInstanceProbeStore::class,
            false,
        );
        $testApp = $this->testApp([], new Bundle(
            $app,
        ));

        $testApp->scoped(static function (ExecutionScope $scope) use ($app): void {
            $serviceStore = $scope->service(StoreInstanceProbeStore::class);
            $app->start($scope);

            self::assertSame(spl_object_id($serviceStore), StoreInstanceProbeScreen::$renderedStoreId);
        });
    }
}

final class AppRenderDiagnosticsProbeScreen implements Screen
{
    public static ?TaskRunSnapshot $renderRun = null;

    public function __invoke(ScreenContext $ctx): Renderable
    {
        if ($ctx->scope instanceof ExecutionScope) {
            self::$renderRun = $ctx->scope->currentRunSnapshot();
            $ctx->scope->cancellation()->cancel();
        }

        return \Phalanx\Tui\Kit\text('app diagnostics');
    }
}

final class StoreInstanceProbeStore extends Store
{
    public function __construct()
    {
    }
}

final class StoreInstanceProbeScreen implements Screen
{
    public static ?int $renderedStoreId = null;

    public function __construct(
        private(set) StoreInstanceProbeStore $store,
    ) {
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        self::$renderedStoreId = spl_object_id($this->store);
        $ctx->scope->cancellation()->cancel();

        return \Phalanx\Tui\Kit\text('store probe');
    }
}
