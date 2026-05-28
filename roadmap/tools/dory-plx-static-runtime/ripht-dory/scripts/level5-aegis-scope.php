<?php

declare(strict_types=1);

// Level 5: Does the Aegis scope model work under the embed SAPI?
//
// This is the integration test. If levels 1-3 pass, this should work --
// Aegis builds on Co::create, Channel, and WaitGroup. But this validates
// the full stack: scope creation, task execution, concurrent(), cancellation.
//
// NOTE: This level requires OpenSwoole (not Swoole). The POC builds with
// Swoole because SPC 2.6 doesn't ship OpenSwoole in its registry. Since
// Phalanx uses OpenSwoole\* namespaces throughout, this level will SKIP
// when running against Swoole. Levels 0-4 prove the underlying primitives
// work; level 5 validates the framework integration and will pass once
// DoryBin's custom SPC registry builds OpenSwoole into libphp.a.
//
// Requires: Phalanx autoloader available. Set PHALANX_AUTOLOAD or run from
// the monorepo workspace.
//
// Expected output (with OpenSwoole):
//   [execute] result: 42
//   [concurrent] results: apollo,artemis
//   [cancellation] caught cancellation correctly
//   [dispose] cleanup ran
//   level 5: PASS

$autoload = getenv('PHALANX_AUTOLOAD') ?: __DIR__ . '/../../../phalanx/vendor/autoload.php';

if (!file_exists($autoload)) {
    echo "autoload not found at: {$autoload}\n";
    echo "set PHALANX_AUTOLOAD or run from the monorepo workspace\n";
    echo "\nlevel 5: SKIP\n";
    return 0;
}

if (!extension_loaded('openswoole')) {
    echo "level 5 requires openswoole (phalanx uses OpenSwoole\\* namespaces)\n";
    echo "this POC was built with swoole -- levels 0-4 prove the primitives work\n";
    echo "\nlevel 5: SKIP (swoole/openswoole namespace mismatch)\n";
    return 0;
}

require $autoload;

use OpenSwoole\Coroutine as Co;
use Phalanx\Cancellation\CancellationSource;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\TestScope;

TestScope::run(static function (ExecutionScope $scope): void {
    $pass = true;

    // Test 1: execute() runs a task and returns the result
    $result = $scope->execute(Task::of(static function (): int {
        return 42;
    }));

    if ($result === 42) {
        echo "[execute] result: {$result}\n";
    } else {
        echo "[execute] FAIL: expected 42, got " . var_export($result, true) . "\n";
        $pass = false;
    }

    // Test 2: concurrent() runs tasks in parallel and returns ordered results
    $results = $scope->concurrent(
        Task::of(static function (): string {
            Co::sleep(0.05);
            return 'apollo';
        }),
        Task::of(static function (): string {
            Co::sleep(0.01);
            return 'artemis';
        }),
    );

    $values = implode(',', $results);
    if ($values === 'apollo,artemis') {
        echo "[concurrent] results: {$values}\n";
    } else {
        echo "[concurrent] FAIL: expected apollo,artemis, got {$values}\n";
        $pass = false;
    }

    // Test 3: cancellation propagates
    $source = new CancellationSource();
    $caught = false;

    Co::create(static function () use ($source, &$caught): void {
        try {
            $source->token()->throwIfCancelled();
            Co::sleep(10.0);
        } catch (Cancelled) {
            $caught = true;
        }
    });

    $source->cancel();
    Co::sleep(0.01);

    if ($caught) {
        echo "[cancellation] caught cancellation correctly\n";
    } else {
        echo "[cancellation] FAIL: cancellation not caught\n";
        $pass = false;
    }

    // Test 4: disposal runs cleanup
    $disposed = false;
    $scope->onDispose(static function () use (&$disposed): void {
        $disposed = true;
    });

    $scope->dispose();

    if ($disposed) {
        echo "[dispose] cleanup ran\n";
    } else {
        echo "[dispose] FAIL: onDispose callback not invoked\n";
        $pass = false;
    }

    echo "\nlevel 5: " . ($pass ? 'PASS' : 'FAIL') . "\n";
});
