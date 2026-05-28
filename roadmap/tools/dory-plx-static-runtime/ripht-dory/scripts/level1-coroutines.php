<?php

declare(strict_types=1);

// Level 1: Do coroutines work under the embed SAPI?
//
// Tests:
//   1. Co::create spawns a coroutine
//   2. Co::sleep yields and resumes
//   3. Multiple coroutines interleave correctly
//
// Expected output:
//   [1] first
//   [2] second
//   [3] third
//   order: first,second,third
//   level 1: PASS

use Swoole\Coroutine as Co;
use Swoole\Coroutine\Scheduler;

$scheduler = new Scheduler();
$scheduler->add(static function (): void {
    $results = [];

    // This coroutine sleeps 100ms then records 'third'
    Co::create(static function () use (&$results): void {
        Co::sleep(0.1);
        $results[] = 'third';
        echo "[3] third\n";
    });

    // This coroutine sleeps 50ms then records 'second'
    Co::create(static function () use (&$results): void {
        Co::sleep(0.05);
        $results[] = 'second';
        echo "[2] second\n";
    });

    // This runs immediately, records 'first'
    Co::create(static function () use (&$results): void {
        $results[] = 'first';
        echo "[1] first\n";
    });

    // Wait for all coroutines to complete
    Co::sleep(0.2);

    $order = implode(',', $results);
    echo "order: {$order}\n";

    $pass = $order === 'first,second,third';
    echo "\nlevel 1: " . ($pass ? 'PASS' : "FAIL (got: {$order})") . "\n";
});
$scheduler->start();
