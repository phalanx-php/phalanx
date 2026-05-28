#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Reactive\Tracker;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// --- 1. Push → read Signal → pop → Signal in dep list ---

$s = new Signal('alpha');
$frame = Tracker::push();
$_ = $s->value;
$deps = Tracker::pop($frame);

assertTrue(count($deps) === 1 && $deps[0] === $s, 'push/read/pop captures the signal');

// --- 2. Read Signal outside push/pop → no accumulation ---

$outside = new Signal('beta');
$_ = $outside->value;

$frame = Tracker::push();
$deps = Tracker::pop($frame);

assertTrue($deps === [], 'reading outside tracking does not accumulate');

// --- 3. Two Signals read in one frame → both in dep list ---

$a = new Signal('gamma');
$b = new Signal('delta');
$frame = Tracker::push();
$_ = $a->value;
$_ = $b->value;
$deps = Tracker::pop($frame);

assertTrue(count($deps) === 2, 'two distinct signals both captured');
assertTrue(in_array($a, $deps, true) && in_array($b, $deps, true), 'both signals present in deps');

// --- 4. Same Signal read twice → appears once (spl_object_id dedup) ---

$dup = new Signal('epsilon');
$frame = Tracker::push();
$_ = $dup->value;
$_ = $dup->value;
$deps = Tracker::pop($frame);

assertTrue(count($deps) === 1, 'duplicate reads deduped by spl_object_id');

// --- 5. Nested frames: inner deps stay inner, outer deps stay outer ---

$outerSig = new Signal('zeta');
$innerSig = new Signal('eta');

$outerFrame = Tracker::push();
$_ = $outerSig->value;

$innerFrame = Tracker::push();
$_ = $innerSig->value;
$innerDeps = Tracker::pop($innerFrame);

$outerDeps = Tracker::pop($outerFrame);

assertTrue(
    count($innerDeps) === 1 && $innerDeps[0] === $innerSig,
    'inner frame captures inner signal only',
);
assertTrue(
    count($outerDeps) === 1 && $outerDeps[0] === $outerSig,
    'outer frame captures outer signal only',
);

// --- 6. Stack depth reaches 2 (Computed-in-Computed simulation) ---

$f0 = Tracker::push();
assertTrue($f0 === 0, 'first frame index is 0');

$f1 = Tracker::push();
assertTrue($f1 === 1, 'second frame index is 1 (depth 2)');

assertTrue(Tracker::isTracking(), 'isTracking true at depth 2');

Tracker::pop($f1);
Tracker::pop($f0);

// --- 7. All above ran on fallback stack (no coroutine context) ---

assertTrue(!Tracker::isTracking(), 'clean state after all tests (fallback stack path)');

fwrite(STDOUT, "TH-B.01 tracker claim passed\n");
