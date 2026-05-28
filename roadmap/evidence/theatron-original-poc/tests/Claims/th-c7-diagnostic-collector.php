#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Theatron\Diagnostic\DiagnosticCollector;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// --- Baseline and first snapshot ---

$collector = DiagnosticCollector::baseline('natural', 'baseline');
$snap = $collector->snapshot(1);

assertTrue($snap->frames === 1, 'frame count passthrough');
assertTrue($snap->policy === 'natural', 'policy passthrough');
assertTrue($snap->churnMode === 'baseline', 'churnMode passthrough');
assertTrue($snap->zendBytes > 0, 'zendBytes is positive');
assertTrue($snap->realBytes > 0, 'realBytes is positive');
assertTrue($snap->elapsed >= 0.0, 'elapsed is non-negative');
assertTrue($snap->gcRoots >= 0, 'gcRoots is non-negative');

// --- Null supervisor yields zeroed pool fields ---

assertTrue($snap->borrowedTaskRuns === 0, 'null supervisor: borrowedTaskRuns is 0');
assertTrue($snap->borrowedScopeFrames === 0, 'null supervisor: borrowedScopeFrames is 0');
assertTrue($snap->borrowedTokens === 0, 'null supervisor: borrowedTokens is 0');
assertTrue($snap->poolHitsTotal === 0, 'null supervisor: poolHitsTotal is 0');
assertTrue($snap->poolMissesTotal === 0, 'null supervisor: poolMissesTotal is 0');
assertTrue($snap->liveTasks === 0, 'null supervisor: liveTasks is 0');
assertTrue($snap->liveScopes === 0, 'null supervisor: liveScopes is 0');

// --- Peak tracking never decreases ---

$peakCollector = DiagnosticCollector::baseline('natural', 'peak-test');
$snap1 = $peakCollector->snapshot(1);
$baseline = $snap1->zendPeak;

$largeAlloc = str_repeat('x', 200_000);
$snap2 = $peakCollector->snapshot(2);

assertTrue($snap2->zendPeak >= $baseline, 'peak never decreases after allocation');

unset($largeAlloc);
$snap3 = $peakCollector->snapshot(3);

assertTrue($snap3->zendPeak >= $snap2->zendPeak, 'peak never decreases after free');

// --- Delta tracks relative to baseline ---

$deltaCollector = DiagnosticCollector::baseline('natural', 'delta-test');
$snap0 = $deltaCollector->snapshot(0);

$alloc = str_repeat('y', 100_000);
$snapAfter = $deltaCollector->snapshot(1);

assertTrue($snapAfter->zendDelta > $snap0->zendDelta, 'delta increases after allocation');

unset($alloc);

// --- Elapsed increases between snapshots ---

$timeCollector = DiagnosticCollector::baseline('natural', 'time-test');
$early = $timeCollector->snapshot(1);

$start = microtime(true);

while (microtime(true) - $start < 0.01) {
    // busy-wait
}

$later = $timeCollector->snapshot(2);

assertTrue($later->elapsed > $early->elapsed, 'elapsed monotonically increases');

// --- Policy and churnMode readable from collector ---

$tagged = DiagnosticCollector::baseline('cache-flush', 'alloc-events');
assertTrue($tagged->policy === 'cache-flush', 'policy readable from collector');
assertTrue($tagged->churnMode === 'alloc-events', 'churnMode readable from collector');

fwrite(STDOUT, "TH-C7 diagnostic collector claim passed\n");
