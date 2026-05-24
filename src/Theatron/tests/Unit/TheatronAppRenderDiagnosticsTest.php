<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\TaskRunSnapshot;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Stage\ScreenMode;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\TheatronApp;
use Phalanx\Theatron\TheatronServiceBundle;
use PHPUnit\Framework\Attributes\Test;

final class TheatronAppRenderDiagnosticsTest extends PhalanxTestCase
{
    #[Test]
    public function appDrawLoopUsesNamedRenderDiagnosticTaskForActiveScreen(): void
    {
        AppRenderDiagnosticsProbeScreen::$renderRun = null;

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $app = new TheatronApp(
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
            'theatron.render.screen ' . AppRenderDiagnosticsProbeScreen::class,
            AppRenderDiagnosticsProbeScreen::$renderRun->name,
        );
        self::assertNull(AppRenderDiagnosticsProbeScreen::$renderRun->currentWait);
    }

    #[Test]
    public function appUsesConfiguredRuntimeStoreInstance(): void
    {
        StoreInstanceProbeScreen::$renderedStoreId = null;

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $stageConfig = new StageConfig(
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
        );
        $app = new TheatronApp(
            Stage::boot($stageConfig),
            Theme::default(),
            [StoreInstanceProbeScreen::class],
            [],
            StoreInstanceProbeStore::class,
            false,
        );
        $testApp = $this->testApp([], new TheatronServiceBundle(
            $app,
        ));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($app): void {
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

        return \Phalanx\Theatron\Ui\text('app diagnostics');
    }
}

final class StoreInstanceProbeStore extends AppStore
{
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

        return \Phalanx\Theatron\Ui\text('store probe');
    }
}
