#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

/**
 * C-level heap diagnostic for the Theatron render path.
 *
 * Exercises the same per-frame allocations as 04-statusbar-diagnostic.php
 * (StatusLineElement, TextElement, sprintf, Painter::paint) and pauses at
 * controlled checkpoints for macOS heap/malloc_history/leaks inspection.
 *
 * Usage:
 *   # Quick ZMM check (no special env vars needed)
 *   php demos/mem-heap-diagnostic.php
 *
 *   # C-level heap diff with macOS tools
 *   MallocStackLogging=1 USE_ZEND_ALLOC=0 php demos/mem-heap-diagnostic.php --heap
 *
 *   # While paused at checkpoints, in another terminal:
 *   heap <pid> > /tmp/heap-t1.txt        # at checkpoint 1
 *   heap <pid> > /tmp/heap-t2.txt        # at checkpoint 2
 *   diff /tmp/heap-t1.txt /tmp/heap-t2.txt
 *
 *   # For C callstacks of live allocations:
 *   malloc_history <pid> -callTree -consolidateAllBySymbol
 *
 *   # For leak detection:
 *   leaks <pid>
 */

$warmupFrames = 500;
$testFrames = 5000;
$heapMode = in_array('--heap', $argv, true);
$signalFile = '/tmp/phalanx-heap-phase';

$scheduler = new OpenSwoole\Coroutine\Scheduler();
$scheduler->add(static function () use ($warmupFrames, $testFrames, $heapMode, $signalFile): void {
    $ui = new Ui();
    $buffer = Buffer::empty(120, 1);
    $paintCtx = new PaintContext(Rect::sized(120, 1), $buffer);

    $barStyle = Style::of(background: Color::indexed(236));
    $barTextStyle = Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236));

    $pid = getmypid();

    $renderFrame = static function (int $frame) use ($ui, $buffer, $paintCtx, $barStyle, $barTextStyle): void {
        $buffer->clear();
        $el = new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' Frame: %d | Zend: %s | Real: %s | %.1fs ',
                        $frame, '2.1MB', '4.0MB', $frame * 0.05),
                    style: $barTextStyle,
                ),
            ],
            style: $barStyle,
        );
        Painter::paint($el, $paintCtx);
        unset($el);
    };

    fprintf(STDERR, "PID: %d\n", $pid);
    fprintf(STDERR, "Warmup: %d frames\n", $warmupFrames);

    for ($i = 0; $i < $warmupFrames; $i++) {
        $renderFrame($i);
    }

    gc_collect_cycles();
    gc_mem_caches();

    $useZendAlloc = getenv('USE_ZEND_ALLOC');
    $zmmDisabled = $useZendAlloc === '0';

    if (!$zmmDisabled) {
        $zendBefore = memory_get_usage(false);
        $realBefore = memory_get_usage(true);
        fprintf(STDERR, "After warmup — Zend: %d bytes, Real: %d bytes\n", $zendBefore, $realBefore);
    } else {
        fprintf(STDERR, "ZMM disabled (USE_ZEND_ALLOC=0) — memory_get_usage unavailable\n");
    }

    if ($heapMode) {
        file_put_contents($signalFile, 'snapshot1');
        fprintf(STDERR, "\n=== CHECKPOINT 1 ===\n");
        fprintf(STDERR, "Take snapshot: heap %d > /tmp/heap-t1.txt\n", $pid);
        fprintf(STDERR, "Then: echo -n go1 > %s\n", $signalFile);

        while (file_get_contents($signalFile) !== 'go1') {
            usleep(100_000);
        }
    }

    fprintf(STDERR, "Rendering %d frames...\n", $testFrames);

    for ($i = 0; $i < $testFrames; $i++) {
        $renderFrame($i + $warmupFrames);

        if (!$zmmDisabled && $i > 0 && $i % 1000 === 0) {
            gc_collect_cycles();
            gc_mem_caches();
            $z = memory_get_usage(false);
            $r = memory_get_usage(true);
            fprintf(STDERR, "  Frame %5d — Zend: %+d, Real: %+d\n",
                $i, $z - $zendBefore, $r - $realBefore);
        }
    }

    gc_collect_cycles();
    gc_mem_caches();

    if (!$zmmDisabled) {
        $zendAfter = memory_get_usage(false);
        $realAfter = memory_get_usage(true);
        fprintf(STDERR, "\nFinal — Zend: %+d bytes, Real: %+d bytes\n",
            $zendAfter - $zendBefore, $realAfter - $realBefore);
        fprintf(STDERR, "GC: %s\n", json_encode(gc_status()));
    }

    if ($heapMode) {
        file_put_contents($signalFile, 'snapshot2');
        fprintf(STDERR, "\n=== CHECKPOINT 2 ===\n");
        fprintf(STDERR, "Take snapshot: heap %d > /tmp/heap-t2.txt\n", $pid);
        fprintf(STDERR, "Then: diff /tmp/heap-t1.txt /tmp/heap-t2.txt\n");
        fprintf(STDERR, "Then: echo -n go2 > %s\n", $signalFile);

        while (file_get_contents($signalFile) !== 'go2') {
            usleep(100_000);
        }

        @unlink($signalFile);
    }

    fprintf(STDERR, "Done.\n");
});
$scheduler->start();
