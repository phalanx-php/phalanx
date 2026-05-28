#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

exit(Archon::command('render-demo', static function (CommandContext $ctx): int {
    $ui = new Ui();

    $stage = Stage::boot(new StageConfig(
        mouseTracking: false,
        handleInput: true,
        activeIntervalUs: 16_667,
    ));

    $w = $stage->width();
    $h = $stage->height();

    $contentText = "Theatron Rendering Engine\nPhase 1 — OpenSwoole 26\n\nDouble-buffered cell diff\nMode 2026 synchronized output\nSGR delta encoding";

    $boxRegion = $stage->region('box', Rect::of(0, 0, $w, $h - 2));
    $spinnerRegion = $stage->region('spinner', Rect::of(2, $h - 2, $w - 4, 1));
    $barRegion = $stage->region('bar', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $newW, int $newH) use ($boxRegion, $spinnerRegion, $barRegion): void {
        $boxRegion->resize(Rect::of(0, 0, $newW, $newH - 2));
        $spinnerRegion->resize(Rect::of(2, $newH - 2, $newW - 4, 1));
        $barRegion->resize(Rect::of(0, $newH - 1, $newW, 1));
    });

    $startTime = microtime(true);
    $counter = 0;
    $frames = 0;
    $spinnerFrame = 0;

    $drawFrame = static function () use (
        $ui, $boxRegion, $spinnerRegion, $barRegion,
        $contentText, &$counter, &$frames, &$spinnerFrame, $startTime,
    ): void {
        $spinnerFrame++;
        $frames++;

        $elapsed = microtime(true) - $startTime;
        $fps = $elapsed > 0.5 ? $frames / $elapsed : 0;
        $mem = memory_get_usage();
        $memLabel = $mem >= 1_048_576
            ? sprintf('%.1fMB', $mem / 1_048_576)
            : sprintf('%.0fKB', $mem / 1_024);

        $boxRegion->draw($ui->panel(
            'Theatron',
            $ui->scrollable($contentText, style: Style::of(color: Color::cyan())),
            style: Style::of(border: Border::Rounded, color: Color::brightCyan()),
        ));

        $spinnerRegion->draw($ui->spinner(
            label: 'Rendering...',
            frame: $spinnerFrame,
            style: Style::of(color: Color::green()),
        ));

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' Counter: %d | Frames: %d | FPS: %.0f', $counter, $frames, $fps),
                    style: Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236)),
                ),
                $ui->text(
                    sprintf('Memory: %s | Elapsed: %.1fs ', $memLabel, $elapsed),
                    style: Style::of(color: Color::brightWhite(), background: Color::indexed(236)),
                ),
            ],
            style: Style::of(background: Color::indexed(236)),
        ));
    };

    $drawFrame();

    $ctx->periodic(1.0, static function () use (&$counter): void {
        $counter++;
    });

    $ctx->periodic(0.08, $drawFrame);

    $stage->run($ctx);

    return 0;
})->default('render-demo')->run(array_slice($_SERVER['argv'] ?? [], 1)));
