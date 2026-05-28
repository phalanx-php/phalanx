#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Stage\ScreenMode;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Store\Slice;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreRegistry;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

final class StoreDemoCounter implements Slice
{
    public string $key {
        get => 'store.demo.counter';
    }

    public function __construct(
        private(set) int $frames = 0,
        private(set) string $mode = 'shared',
    ) {
    }
}

final class StoreSharedPanel implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $counter = $ctx->lens(StoreDemoCounter::class)->value;

        return $ctx->ui->column(
            $ctx->ui->text('Store Shared State', Style::of(size: Size::fixed(1), color: Color::brightCyan())),
            $ctx->ui->text(sprintf('Frames: %d', $counter->frames)),
            $ctx->ui->text(sprintf('Mode: %s', $counter->mode)),
        );
    }
}

exit(Archon::command('store-shared-state', static function (CommandContext $ctx): int {
    $maxFrames = $ctx->options->get('frames') !== null ? max(1, (int) $ctx->options->get('frames')) : null;
    $capture = $ctx->options->flag('capture');
    $stage = Stage::boot(new StageConfig(
        screenMode: $maxFrames === null ? ScreenMode::Alternate : ScreenMode::Inline,
        handleInput: $maxFrames === null,
        activeIntervalUs: 50_000,
        captureFile: $capture ? '/tmp/theatron-11-store-shared-state.bin' : null,
    ));

    $registry = StoreRegistry::fromDefinitions(Store::concurrent('demo-store', StoreDemoCounter::class));
    $registry->start($ctx);
    $lens = $registry->lens();
    $writer = $registry->writer();
    $mount = new MountedComponent(new StoreSharedPanel(), $ctx, $lens);
    $ui = new Ui();

    $w = $stage->width();
    $h = $stage->height();
    $main = $stage->region('main', Rect::of(0, 0, $w, $h - 1));
    $status = $stage->region('status', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $width, int $height) use ($main, $status): void {
        $main->resize(Rect::of(0, 0, $width, $height - 1));
        $status->resize(Rect::of(0, $height - 1, $width, 1));
    });

    $frames = 0;
    $needsDraw = true;

    $stage->onDraw(static function () use (
        $writer,
        $mount,
        $main,
        $status,
        $ui,
        &$frames,
        &$needsDraw,
    ): void {
        $frames++;
        $writer->update(
            StoreDemoCounter::class,
            static fn(StoreDemoCounter $slice): StoreDemoCounter => new StoreDemoCounter(
                frames: $slice->frames + 1,
                mode: $slice->mode,
            ),
        );

        if ($needsDraw || $mount->consumeDirty()) {
            $main->draw($ui->panel('Shared Store', $mount->render(), Style::of(border: Border::Rounded)));
            $needsDraw = false;
        }

        $status->draw(new StatusLineElement([
            $ui->text(sprintf(' TH-A.01 | Store frames: %d ', $frames), Style::of(size: Size::fill())),
        ]));
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
]))->default('store-shared-state')->run(array_slice($_SERVER['argv'] ?? [], 1)));
