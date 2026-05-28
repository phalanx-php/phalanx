#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Diagnostic\DiagnosticCollector;
use Phalanx\Theatron\Diagnostic\DiagnosticFormatter;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

exit(Archon::command('statusbar-diagnostic', static function (CommandContext $ctx): int {
    $mode = (string) ($ctx->options->get('mode') ?? 'normal');
    $maxFrames = $ctx->options->get('frames') !== null ? max(1, (int) $ctx->options->get('frames')) : null;
    $capture = $ctx->options->flag('capture');
    $syncOutput = $mode === 'sync';
    $fullSgr = $mode === 'full-sgr';
    $captureFile = $capture ? '/tmp/theatron-capture.bin' : null;
    $ui = new Ui();

    $stage = Stage::boot(new StageConfig(
        handleInput: $maxFrames === null,
        activeIntervalUs: 50_000,
        syncOutput: $syncOutput,
        captureFile: $captureFile,
        fullSgr: $fullSgr,
    ));

    $w = $stage->width();
    $h = $stage->height();

    $collector = DiagnosticCollector::baseline($mode, 'none');

    $diagnosticText = implode("\n", [
        'StatusBar Diagnostic',
        '',
        'This demo isolates StatusBar rendering.',
        'The final terminal row is the actual status bar.',
        '',
        'Modes:',
        '  --mode=normal    SGR delta, no sync (default)',
        '  --mode=sync      Mode 2026 synchronized output',
        '  --mode=full-sgr  Full SGR per cell, no delta',
        '',
        'Add --capture to write ANSI output to /tmp/theatron-capture.bin',
    ]);

    $boxRegion = $stage->region('box', Rect::of(0, 0, $w, $h - 1));
    $barRegion = $stage->region('bar', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($boxRegion, $barRegion): void {
        $boxRegion->resize(Rect::of(0, 0, $nw, $nh - 1));
        $barRegion->resize(Rect::of(0, $nh - 1, $nw, 1));
    });

    $frames = 0;

    $panelElement = $ui->panel(
        sprintf('Diagnostic [%s]', $mode),
        $ui->scrollable($diagnosticText, style: Style::of(color: Color::cyan())),
        style: Style::of(border: Border::Rounded, color: Color::brightCyan()),
    );

    $barBg = Style::of(background: Color::indexed(236));

    $drawFrame = static function (Stage $stage) use (
        $ui,
        $boxRegion,
        $barRegion,
        $panelElement,
        $barBg,
        $collector,
        &$frames,
        $maxFrames,
        $ctx,
    ): void {
        $frames++;

        $snap = $collector->snapshot($frames);
        $sections = DiagnosticFormatter::simpleStatusBar($snap);

        $boxRegion->draw($panelElement);

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    $sections['identity'],
                    style: Style::of(
                        size: Size::fixed(24),
                        color: Color::brightWhite(),
                        background: Color::indexed(236),
                    ),
                ),
                $ui->text(
                    $sections['memory'],
                    style: Style::of(
                        size: Size::fill(),
                        color: Color::brightGreen(),
                        background: Color::indexed(236),
                    ),
                ),
                $ui->text(
                    $sections['time'],
                    style: Style::of(
                        size: Size::fixed(10),
                        color: Color::indexed(245),
                        background: Color::indexed(236),
                    ),
                ),
            ],
            style: $barBg,
        ));

        if ($maxFrames !== null && $frames >= $maxFrames) {
            $ctx->cancellation()->cancel();
        }
    };

    $stage->onDraw($drawFrame);

    $stage->run($ctx);

    return 0;
}, new CommandConfig(options: [
    Opt::value('mode', desc: 'Output mode: normal, sync, full-sgr'),
    Opt::value('frames', desc: 'Run N frames then exit'),
    Opt::flag('capture', desc: 'Write capture file'),
]))->default('statusbar-diagnostic')->run(array_slice($_SERVER['argv'] ?? [], 1)));
