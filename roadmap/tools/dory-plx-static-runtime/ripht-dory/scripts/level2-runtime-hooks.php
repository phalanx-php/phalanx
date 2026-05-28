<?php

declare(strict_types=1);

// Level 2: Do runtime hooks make blocking I/O coroutine-aware?
//
// Tests:
//   1. Runtime::enableCoroutine() patches PHP stream functions
//   2. sleep()/usleep() yield instead of blocking
//   3. Two concurrent sleeps complete in ~parallel time, not sequential
//
// Expected output:
//   runtime hooks enabled
//   [a] sleep done (after ~100ms)
//   [b] sleep done (after ~50ms)
//   order: b,a
//   elapsed: ~0.1s (not ~0.15s)
//   level 2: PASS

use Swoole\Coroutine as Co;
use Swoole\Coroutine\Scheduler;
use Swoole\Runtime;

Runtime::enableCoroutine(SWOOLE_HOOK_SLEEP);
echo "runtime hooks enabled\n";

$scheduler = new Scheduler();
$scheduler->add(static function (): void {
    $wg = new \Swoole\Coroutine\WaitGroup();
    $results = [];
    $start = hrtime(true);

    $wg->add();
    Co::create(static function () use ($wg, &$results): void {
        usleep(100_000); // 100ms -- hooked, should yield
        $results[] = 'a';
        echo "[a] sleep done\n";
        $wg->done();
    });

    $wg->add();
    Co::create(static function () use ($wg, &$results): void {
        usleep(50_000); // 50ms -- hooked, should yield
        $results[] = 'b';
        echo "[b] sleep done\n";
        $wg->done();
    });

    $wg->wait(2.0);

    $elapsed = (hrtime(true) - $start) / 1_000_000_000;
    $order = implode(',', $results);

    echo "order: {$order}\n";
    echo sprintf("elapsed: %.3fs\n", $elapsed);

    // If hooks work: both run concurrently, total ~100ms
    // If hooks don't work: sequential, total ~150ms
    $concurrent = $elapsed < 0.14;
    $correctOrder = $order === 'b,a';

    echo "\nlevel 2: " . ($concurrent && $correctOrder ? 'PASS' : 'FAIL') . "\n";

    if (!$concurrent) {
        echo "  FAIL: elapsed {$elapsed}s suggests sequential execution (hooks not working)\n";
    }
    if (!$correctOrder) {
        echo "  FAIL: expected order b,a but got {$order}\n";
    }
});
$scheduler->start();
