#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Supervisor\Supervisor;
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

exit(Archon::command('memory-policy-diagnostic', static function (CommandContext $ctx): int {
    $ringSize = 64;
    $mode = (string) ($ctx->options->get('mode') ?? 'normal');
    $churn = (string) ($ctx->options->get('churn') ?? 'baseline');
    $memoryPolicy = (string) ($ctx->options->get('memory-policy') ?? 'natural');
    $maxFrames = $ctx->options->get('frames') !== null ? max(1, (int) $ctx->options->get('frames')) : null;
    $capture = $ctx->options->flag('capture');
    $syncOutput = $mode === 'sync';
    $fullSgr = $mode === 'full-sgr';
    $captureFile = $capture ? '/tmp/theatron-memory-policy-capture.bin' : null;
    $ui = new Ui();
    $pool = [];
    $retained = [];
    $poolCursor = 0;
    $corruptionCount = 0;

    $pooledEvent = static function (int $frame, string $label) use (&$pool, &$poolCursor, $ringSize): stdClass {
        $idx = $poolCursor % $ringSize;

        if ($poolCursor < $ringSize) {
            $pool[] = (object) [
                'frame' => $frame,
                'label' => $label,
                'payload' => str_repeat('x', 32),
            ];
        } else {
            $pool[$idx]->frame = $frame;
            $pool[$idx]->label = $label;
            $pool[$idx]->payload = str_repeat('x', 32);
        }

        $poolCursor++;

        return $pool[$idx];
    };

    $runChurn = static function (
        string $mode,
        int $frame,
    ) use (
        &$corruptionCount,
        &$retained,
        $pooledEvent,
        $ringSize,
    ): void {
        match ($mode) {
            'alloc-events' => (static function () use ($frame, $ringSize): void {
                $events = [];

                for ($i = 0; $i < $ringSize; $i++) {
                    $events[] = [
                        'frame' => $frame,
                        'label' => "event-{$i}",
                        'payload' => str_repeat('x', 32),
                    ];
                }
            })(),
            'private-pool' => (static function () use ($frame, $pooledEvent, $ringSize): void {
                for ($i = 0; $i < $ringSize; $i++) {
                    $event = $pooledEvent($frame, "private-{$i}");
                    $unused = $event->frame;
                    unset($unused);
                }
            })(),
            'public-pool-retained' => (static function () use (
                &$corruptionCount,
                &$retained,
                $frame,
                $pooledEvent,
                $ringSize,
            ): void {
                $event = $pooledEvent($frame, 'borrowed');

                if (count($retained) < $ringSize) {
                    $retained[] = $event;

                    return;
                }

                if ($retained[0]->frame !== 0) {
                    $corruptionCount++;
                }
            })(),
            'snapshot-boundary' => (static function () use ($frame, $pooledEvent): void {
                $event = $pooledEvent($frame, 'snapshot');
                $snapshot = [
                    'frame' => $event->frame,
                    'label' => $event->label,
                    'payload' => $event->payload,
                ];
                $unused = $snapshot['frame'];
                unset($unused);
            })(),
            default => null,
        };
    };

    $stage = Stage::boot(new StageConfig(
        handleInput: $maxFrames === null,
        activeIntervalUs: 50_000,
        syncOutput: $syncOutput,
        captureFile: $captureFile,
        fullSgr: $fullSgr,
        flushMemoryCaches: $memoryPolicy === 'cache-flush',
    ));

    $w = $stage->width();
    $h = $stage->height();

    $supervisor = null;

    try {
        $supervisor = $ctx->service(Supervisor::class);
    } catch (\RuntimeException) {
    }

    $collector = DiagnosticCollector::baseline($memoryPolicy, $churn);

    $helpLines = [
        'Memory Policy Diagnostic',
        '',
        'Policies:',
        '  --memory-policy=natural      no explicit allocator cache flush',
        '  --memory-policy=cache-flush  gc_mem_caches() every 60 frames',
        '',
        'Churn modes:',
        '  --churn=baseline             no extra allocation',
        '  --churn=alloc-events         fresh arrays every frame',
        '  --churn=private-pool         ring-buffer reuse',
        '  --churn=public-pool-retained retained references across frames',
        '  --churn=snapshot-boundary    snapshot then discard',
    ];

    $boxRegion = $stage->region('box', Rect::of(0, 0, $w, $h - 1));
    $barRegion = $stage->region('bar', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($boxRegion, $barRegion): void {
        $boxRegion->resize(Rect::of(0, 0, $nw, $nh - 1));
        $barRegion->resize(Rect::of(0, $nh - 1, $nw, 1));
    });

    $frames = 0;
    $barBg = Style::of(background: Color::indexed(236));

    $drawFrame = static function (Stage $stage) use (
        $ui,
        $boxRegion,
        $barRegion,
        $barBg,
        $collector,
        $supervisor,
        &$frames,
        $churn,
        $runChurn,
        $maxFrames,
        &$corruptionCount,
        $ctx,
        $helpLines,
    ): void {
        $runChurn($churn, $frames);
        $frames++;

        $snap = $collector->snapshot($frames, $supervisor);
        $sections = DiagnosticFormatter::statusBar($snap);

        $panelContent = implode("\n", $helpLines)
            . "\n\n"
            . DiagnosticFormatter::panelSummary($snap);

        if ($corruptionCount > 0) {
            $panelContent .= sprintf("\n\n Corruptions: %d", $corruptionCount);
        }

        $boxRegion->draw(
            $ui->panel(
                sprintf('Memory [%s/%s]', $snap->policy, $snap->churnMode),
                $ui->scrollable($panelContent, style: Style::of(color: Color::cyan())),
                style: Style::of(border: Border::Rounded, color: Color::brightCyan()),
            ),
        );

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    $sections['identity'],
                    style: Style::of(
                        size: Size::fixed(32),
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
                    $sections['runtime'],
                    style: Style::of(
                        size: Size::fixed(30),
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
    Opt::value('churn', desc: 'Memory churn mode'),
    Opt::value('memory-policy', desc: 'Memory policy: natural, cache-flush'),
    Opt::flag('capture', desc: 'Write capture file'),
]))->default('memory-policy-diagnostic')->run(array_slice($_SERVER['argv'] ?? [], 1)));
