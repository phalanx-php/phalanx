<?php

declare(strict_types=1);

// Level 3: Do Channel and WaitGroup work under the embed SAPI?
//
// These are the coordination primitives Aegis builds concurrent(), race(),
// any(), and map() on top of. If these deadlock, the Aegis scope model
// is blocked.
//
// Tests:
//   1. Channel push/pop across coroutines
//   2. WaitGroup add/done/wait
//   3. Bounded channel with backpressure
//   4. Channel timeout (pop with deadline)
//
// Expected output:
//   [channel] received: leonidas
//   [waitgroup] all tasks completed
//   [bounded] received 3 items in order
//   [timeout] pop timed out correctly
//   level 3: PASS

use Swoole\Coroutine as Co;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Scheduler;
use Swoole\Coroutine\WaitGroup;

$scheduler = new Scheduler();
$scheduler->add(static function (): void {
    $pass = true;

    // Test 1: Basic channel push/pop
    $ch = new Channel(1);

    Co::create(static function () use ($ch): void {
        Co::sleep(0.05);
        $ch->push('leonidas');
    });

    $value = $ch->pop(1.0);
    if ($value === 'leonidas') {
        echo "[channel] received: {$value}\n";
    } else {
        echo "[channel] FAIL: expected 'leonidas', got " . var_export($value, true) . "\n";
        $pass = false;
    }

    // Test 2: WaitGroup
    $wg = new WaitGroup();
    $completed = [];

    for ($i = 0; $i < 3; $i++) {
        $wg->add();
        Co::create(static function () use ($wg, &$completed, $i): void {
            Co::sleep(0.01 * ($i + 1));
            $completed[] = $i;
            $wg->done();
        });
    }

    $wgResult = $wg->wait(2.0);
    if ($wgResult && count($completed) === 3) {
        echo "[waitgroup] all tasks completed\n";
    } else {
        echo "[waitgroup] FAIL: completed=" . count($completed) . " wgResult=" . var_export($wgResult, true) . "\n";
        $pass = false;
    }

    // Test 3: Bounded channel (capacity 2, send 3 items)
    $bounded = new Channel(2);
    $received = [];

    Co::create(static function () use ($bounded): void {
        $bounded->push('alpha');
        $bounded->push('beta');
        // This push blocks until a pop happens (channel full)
        $bounded->push('gamma');
    });

    Co::sleep(0.01);
    $received[] = $bounded->pop(1.0);
    $received[] = $bounded->pop(1.0);
    $received[] = $bounded->pop(1.0);

    if ($received === ['alpha', 'beta', 'gamma']) {
        echo "[bounded] received 3 items in order\n";
    } else {
        echo "[bounded] FAIL: " . var_export($received, true) . "\n";
        $pass = false;
    }

    // Test 4: Channel timeout
    $empty = new Channel(1);
    $start = hrtime(true);
    $timedOut = $empty->pop(0.1); // should timeout after 100ms
    $elapsed = (hrtime(true) - $start) / 1_000_000_000;

    if ($timedOut === false && $elapsed >= 0.09 && $elapsed < 0.2) {
        echo "[timeout] pop timed out correctly\n";
    } else {
        echo "[timeout] FAIL: result=" . var_export($timedOut, true) . " elapsed={$elapsed}\n";
        $pass = false;
    }

    echo "\nlevel 3: " . ($pass ? 'PASS' : 'FAIL') . "\n";
});
$scheduler->start();
