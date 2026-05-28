#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\DevTools\AegisRuntimeStoreProjector;
use Phalanx\Theatron\DevTools\DevToolsPanel;
use Phalanx\Theatron\DevTools\RuntimeMetricsSlice;
use Phalanx\Theatron\DevTools\RuntimeScopeSlice;
use Phalanx\Theatron\Stage\ScreenMode;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreRegistry;

exit(Archon::command('store-devtools', static function (CommandContext $ctx): int {
    $maxFrames = $ctx->options->get('frames') !== null ? max(1, (int) $ctx->options->get('frames')) : null;
    $capture = $ctx->options->flag('capture');
    $stage = Stage::boot(new StageConfig(
        screenMode: $maxFrames === null ? ScreenMode::Alternate : ScreenMode::Inline,
        handleInput: $maxFrames === null,
        activeIntervalUs: 50_000,
        captureFile: $capture ? '/tmp/theatron-13-store-devtools.bin' : null,
    ));

    $registry = StoreRegistry::fromDefinitions(Store::concurrent(
        'devtools-store',
        RuntimeMetricsSlice::class,
        RuntimeScopeSlice::class,
    ));
    $registry->start($ctx);

    $projector = new AegisRuntimeStoreProjector($ctx->service(RuntimeContext::class), $registry->writer());
    $mount = new MountedComponent(new DevToolsPanel(), $ctx, $registry->lens());

    $w = $stage->width();
    $h = $stage->height();
    $main = $stage->region('main', Rect::of(0, 0, $w, $h));

    $stage->onResize(static function (int $width, int $height) use ($main): void {
        $main->resize(Rect::of(0, 0, $width, $height));
    });

    $frames = 0;
    $needsDraw = true;

    $stage->onDraw(static function () use (
        $ctx,
        $projector,
        $mount,
        $main,
        &$frames,
        &$needsDraw,
    ): void {
        $frames++;
        $projector->project($ctx, $frames);

        if ($needsDraw || $mount->consumeDirty()) {
            $main->draw($mount->render());
            $needsDraw = false;
        }
    });

    try {
        if ($maxFrames !== null) {
            $stage->start($ctx);

            while ($frames < $maxFrames) {
                $ctx->delay(0.01);
            }

            return 0;
        }

        $stage->run($ctx);
    } finally {
        $stage->stop();
        $mount->dispose();
    }

    return 0;
}, new CommandConfig(options: [
    Opt::value('frames', desc: 'Run N frames then exit'),
    Opt::flag('capture', desc: 'Write capture file'),
]))->default('store-devtools')->run(array_slice($_SERVER['argv'] ?? [], 1)));
