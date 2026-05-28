#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Theatron\Diagnostic\DiagnosticFormatter;
use Phalanx\Theatron\Diagnostic\DiagnosticSnapshot;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function snap(
    int $frames = 1,
    string $policy = 'natural',
    string $churnMode = 'baseline',
    int $zendBytes = 2_097_152,
    int $zendDelta = 0,
    int $zendPeak = 2_097_152,
    int $realBytes = 4_194_304,
    int $realDelta = 0,
    int $realPeak = 4_194_304,
    int $borrowedTaskRuns = 0,
    int $borrowedScopeFrames = 0,
    int $borrowedTokens = 0,
    int $poolHitsTotal = 0,
    int $poolMissesTotal = 0,
    int $liveTasks = 0,
    int $liveScopes = 0,
    float $elapsed = 1.0,
    int $gcRoots = 0,
): DiagnosticSnapshot {
    return new DiagnosticSnapshot(
        frames: $frames,
        policy: $policy,
        churnMode: $churnMode,
        zendBytes: $zendBytes,
        zendDelta: $zendDelta,
        zendPeak: $zendPeak,
        realBytes: $realBytes,
        realDelta: $realDelta,
        realPeak: $realPeak,
        borrowedTaskRuns: $borrowedTaskRuns,
        borrowedScopeFrames: $borrowedScopeFrames,
        borrowedTokens: $borrowedTokens,
        poolHitsTotal: $poolHitsTotal,
        poolMissesTotal: $poolMissesTotal,
        liveTasks: $liveTasks,
        liveScopes: $liveScopes,
        elapsed: $elapsed,
        gcRoots: $gcRoots,
    );
}

// --- formatDelta edge cases via statusBar ---

$zeroDelta = DiagnosticFormatter::statusBar(snap(zendDelta: 0, realDelta: 0));
assertTrue(str_contains($zeroDelta['memory'], '~0B'), 'zero delta formats as ~0B');

$positiveDelta = DiagnosticFormatter::statusBar(snap(zendDelta: 2048));
assertTrue(str_contains($positiveDelta['memory'], '+2.0KB'), 'positive delta has + prefix');

$negativeDelta = DiagnosticFormatter::statusBar(snap(zendDelta: -1536));
assertTrue(str_contains($negativeDelta['memory'], '-1.5KB'), 'negative delta preserves sign');

// --- formatBytes boundaries via simpleStatusBar ---

$bytesLow = DiagnosticFormatter::simpleStatusBar(snap(zendBytes: 512, realBytes: 512));
assertTrue(str_contains($bytesLow['memory'], '512B'), 'sub-KB shows raw bytes');

$bytesKb = DiagnosticFormatter::simpleStatusBar(snap(zendBytes: 1536, realBytes: 1536));
assertTrue(str_contains($bytesKb['memory'], '1.5KB'), 'KB boundary formats correctly');

$bytesMb = DiagnosticFormatter::simpleStatusBar(snap(zendBytes: 2_097_152, realBytes: 2_097_152));
assertTrue(str_contains($bytesMb['memory'], '2.0MB'), 'MB boundary formats correctly');

$bytesGb = DiagnosticFormatter::simpleStatusBar(
    snap(zendBytes: 1_073_741_824, realBytes: 1_073_741_824),
);
assertTrue(str_contains($bytesGb['memory'], '1.0GB'), 'GB boundary formats correctly');

// --- statusBar return shape ---

$bar = DiagnosticFormatter::statusBar(snap(
    frames: 42,
    policy: 'natural',
    churnMode: 'baseline',
));
assertTrue(
    isset($bar['identity'], $bar['memory'], $bar['runtime']),
    'statusBar returns identity/memory/runtime keys',
);
assertTrue(str_contains($bar['identity'], '42'), 'identity contains frame count');
assertTrue(
    str_contains($bar['identity'], 'natural/baseline'),
    'identity contains policy/churnMode',
);

// --- simpleStatusBar return shape ---

$simple = DiagnosticFormatter::simpleStatusBar(snap(frames: 7, policy: 'cache-flush'));
assertTrue(
    isset($simple['identity'], $simple['memory'], $simple['time']),
    'simpleStatusBar returns identity/memory/time keys',
);
assertTrue(str_contains($simple['identity'], '7'), 'simple identity contains frame count');
assertTrue(
    str_contains($simple['identity'], 'cache-flush'),
    'simple identity contains policy',
);
assertTrue(str_contains($simple['time'], 's'), 'simple time contains seconds');

// --- panelSummary content ---

$panel = DiagnosticFormatter::panelSummary(snap(
    zendBytes: 2_097_152,
    zendDelta: 1024,
    zendPeak: 2_200_000,
    realBytes: 4_194_304,
    realDelta: 0,
    realPeak: 4_194_304,
    borrowedTaskRuns: 3,
    borrowedScopeFrames: 1,
    borrowedTokens: 0,
    poolHitsTotal: 100,
    poolMissesTotal: 2,
    liveTasks: 3,
    liveScopes: 2,
    gcRoots: 5,
));
assertTrue(str_contains($panel, 'Alloc'), 'panel contains Alloc section');
assertTrue(str_contains($panel, 'RSS'), 'panel contains RSS section');
assertTrue(str_contains($panel, 'Pool:'), 'panel contains Pool section');
assertTrue(str_contains($panel, 'taskRun 3'), 'panel shows taskRun count');
assertTrue(str_contains($panel, 'scopeFrame 1'), 'panel shows scopeFrame count');
assertTrue(str_contains($panel, 'Hits: 100'), 'panel shows hit count');
assertTrue(str_contains($panel, 'Misses: 2'), 'panel shows miss count');
assertTrue(str_contains($panel, 'GC roots: 5'), 'panel shows GC roots');
assertTrue(str_contains($panel, 'Live tasks: 3'), 'panel shows live tasks');
assertTrue(str_contains($panel, 'Live scopes: 2'), 'panel shows live scopes');

// --- statusBar identity width stability across frame counts ---

$lengths = [];

foreach ([1, 10, 100, 1000, 10000] as $f) {
    $bar = DiagnosticFormatter::statusBar(snap(frames: $f));
    $lengths[$f] = strlen($bar['identity']);
}

$unique = array_unique($lengths);
assertTrue(
    count($unique) === 1,
    sprintf(
        'statusBar identity width stable across frame counts (got: %s)',
        implode(',', $unique),
    ),
);

// --- statusBar memory width stability ---

$memLengths = [];

foreach ([1, 10, 100, 1000, 10000] as $f) {
    $bar = DiagnosticFormatter::statusBar(snap(frames: $f));
    $memLengths[$f] = strlen($bar['memory']);
}

$uniqueMem = array_unique($memLengths);
assertTrue(
    count($uniqueMem) === 1,
    sprintf(
        'statusBar memory width stable across frame counts (got: %s)',
        implode(',', $uniqueMem),
    ),
);

// --- totalCapacity floor prevents zero denominator ---

$zeroBar = DiagnosticFormatter::statusBar(snap(
    borrowedTaskRuns: 0,
    borrowedScopeFrames: 0,
    borrowedTokens: 0,
    liveTasks: 0,
));
assertTrue(
    str_contains($zeroBar['runtime'], 'Pool:'),
    'statusBar runtime renders with all-zero pool stats',
);

fwrite(STDOUT, "TH-C6 diagnostic formatter claim passed\n");
