#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;

exit(Archon::command('mem-diagnostic', static function (CommandContext $ctx): int {
    $test = (string) ($ctx->options->get('test') ?? 'delay');
    $iterations = max(1, (int) ($ctx->options->get('n') ?? 2000));
    $intervalUs = max(1000, (int) ($ctx->options->get('interval') ?? 50000));
    $intervalSec = $intervalUs / 1_000_000;

    fprintf(STDERR, "Test: %s | Iterations: %d | Interval: %dμs\n\n", $test, $iterations, $intervalUs);
    fprintf(STDERR, "%8s | %12s | %+12s | %12s\n", 'Iter', 'Zend', 'Delta', 'Real');
    fprintf(STDERR, "%s\n", str_repeat('-', 55));

    $startMem = memory_get_usage();

    $printRow = static function (int $i, int $startMem): void {
        $mem = memory_get_usage();
        $real = memory_get_usage(true);
        fprintf(STDERR, "%8d | %10dB | %+10dB | %10dB\n", $i, $mem, $mem - $startMem, $real);
    };

    match ($test) {
        'delay' => (static function () use ($ctx, $iterations, $intervalSec, $startMem, $printRow): void {
            $printRow(0, $startMem);
            for ($i = 1; $i <= $iterations; $i++) {
                $ctx->delay($intervalSec);
                if ($i <= 5 || $i % 200 === 0) {
                    $printRow($i, $startMem);
                }
            }
        })(),

        'periodic' => (static function () use ($ctx, $iterations, $intervalSec, $startMem, $printRow): void {
            $count = 0;
            $printRow(0, $startMem);
            $ctx->periodic($intervalSec, static function () use (&$count, $iterations, $startMem, $printRow, $ctx): void {
                $count++;
                if ($count <= 5 || $count % 200 === 0) {
                    $printRow($count, $startMem);
                }
                if ($count >= $iterations) {
                    $ctx->cancellation()->cancel();
                }
            });
            while (!$ctx->isCancelled) {
                $ctx->delay(0.5);
            }
        })(),

        'both' => (static function () use ($ctx, $iterations, $intervalSec, $startMem, $printRow): void {
            $count = 0;
            $printRow(0, $startMem);
            $ctx->periodic($intervalSec, static function () use (&$count): void {
                $count++;
            });
            for ($i = 1; $i <= $iterations; $i++) {
                $ctx->delay(0.1);
                if ($i <= 5 || $i % 200 === 0) {
                    $printRow($i, $startMem);
                }
            }
            $ctx->cancellation()->cancel();
        })(),

        'bare-sleep' => (static function () use ($iterations, $intervalUs, $startMem, $printRow): void {
            $printRow(0, $startMem);
            for ($i = 1; $i <= $iterations; $i++) {
                \OpenSwoole\Coroutine::usleep((int) ($intervalUs));
                if ($i <= 5 || $i % 200 === 0) {
                    $printRow($i, $startMem);
                }
            }
        })(),

        'token-churn' => (static function () use ($ctx, $iterations, $startMem, $printRow): void {
            $token = $ctx->cancellation();
            $printRow(0, $startMem);
            for ($i = 1; $i <= $iterations; $i++) {
                $key = $token->onCancel(static function (): void {});
                $token->offCancel($key);
                if ($i <= 5 || $i % 200 === 0) {
                    $printRow($i, $startMem);
                }
            }
        })(),

        'delay-no-wait' => (static function () use ($ctx, $iterations, $intervalUs, $startMem, $printRow): void {
            $token = $ctx->cancellation();
            $printRow(0, $startMem);
            for ($i = 1; $i <= $iterations; $i++) {
                $cid = \OpenSwoole\Coroutine::getCid();
                $key = $token->onCancel(static function () use ($cid): void {
                    if ($cid > 0 && \OpenSwoole\Coroutine::exists($cid)) {
                        \OpenSwoole\Coroutine::cancel($cid);
                    }
                });
                \OpenSwoole\Coroutine::usleep($intervalUs);
                $token->offCancel($key);
                if ($i <= 5 || $i % 200 === 0) {
                    $printRow($i, $startMem);
                }
            }
        })(),

        'delay-closure-only' => (static function () use ($iterations, $intervalUs, $startMem, $printRow): void {
            $printRow(0, $startMem);
            for ($i = 1; $i <= $iterations; $i++) {
                $cid = \OpenSwoole\Coroutine::getCid();
                $fn = static function () use ($cid): void {
                    if ($cid > 0 && \OpenSwoole\Coroutine::exists($cid)) {
                        \OpenSwoole\Coroutine::cancel($cid);
                    }
                };
                \OpenSwoole\Coroutine::usleep($intervalUs);
                unset($fn);
                if ($i <= 5 || $i % 200 === 0) {
                    $printRow($i, $startMem);
                }
            }
        })(),

        'co-sleep-only' => (static function () use ($iterations, $intervalUs, $startMem, $printRow): void {
            $printRow(0, $startMem);
            for ($i = 1; $i <= $iterations; $i++) {
                \Phalanx\Concurrency\Co::sleep($intervalUs / 1_000_000);
                if ($i <= 5 || $i % 200 === 0) {
                    $printRow($i, $startMem);
                }
            }
        })(),

        'delay-anatomy' => (static function () use ($ctx, $iterations, $intervalUs, $startMem, $printRow): void {
            fprintf(STDERR, "\n--- Anatomy: 100 delay() calls, memory after each piece ---\n");
            $token = $ctx->cancellation();
            $before = memory_get_usage();
            for ($i = 0; $i < 100; $i++) {
                $ctx->delay($intervalUs / 1_000_000);
            }
            $after = memory_get_usage();
            fprintf(STDERR, "scope->delay x100: %+d bytes (%+d bytes/call)\n", $after - $before, ($after - $before) / 100);

            gc_collect_cycles();
            gc_mem_caches();
            $afterGc = memory_get_usage();
            fprintf(STDERR, "After GC:          %+d bytes (%+d reclaimed)\n\n", $afterGc - $before, $after - $afterGc);

            fprintf(STDERR, "--- Now 2000 with gc every 200 ---\n");
            $base = memory_get_usage();
            for ($i = 1; $i <= $iterations; $i++) {
                $ctx->delay($intervalUs / 1_000_000);
                if ($i % 200 === 0) {
                    gc_collect_cycles();
                    gc_mem_caches();
                    $m = memory_get_usage();
                    fprintf(STDERR, "%5d | %+10dB | after gc\n", $i, $m - $base);
                }
            }
            fprintf(STDERR, "\nFinal: %+dB growth with periodic GC\n", memory_get_usage() - $base);
        })(),

        'delay-bisect' => (static function () use ($ctx, $iterations, $intervalUs, $startMem, $printRow): void {
            $token = $ctx->cancellation();

            $unwrap = static function (object $obj): object {
                $rc = new ReflectionClass($obj);
                foreach ($rc->getProperties() as $prop) {
                    if ($prop->getName() === 'inner') {
                        $prop->setAccessible(true);
                        return $prop->getValue($obj);
                    }
                }
                return $obj;
            };
            $lifecycleScope = $unwrap($ctx);
            fprintf(STDERR, "Unwrapped scope: %s\n", get_class($lifecycleScope));

            $supervisor = null;
            $currentRun = null;
            $rc = new ReflectionClass($lifecycleScope);
            foreach ($rc->getProperties() as $prop) {
                $prop->setAccessible(true);
                if ($prop->getName() === 'supervisor') {
                    $supervisor = $prop->getValue($lifecycleScope);
                }
                if ($prop->getName() === 'currentRun') {
                    $currentRun = $prop->getValue($lifecycleScope);
                }
            }
            fprintf(STDERR, "supervisor=%s currentRun=%s\n\n",
                $supervisor !== null ? get_class($supervisor) : 'null',
                $currentRun !== null ? get_class($currentRun) : 'null',
            );

            fprintf(STDERR, "--- A: onCancel + Co::sleep + offCancel (no WaitReason) ---\n");
            $base = memory_get_usage();
            for ($i = 0; $i < 500; $i++) {
                $cid = \OpenSwoole\Coroutine::getCid();
                $key = $token->onCancel(static function () use ($cid): void {
                    if ($cid > 0 && \OpenSwoole\Coroutine::exists($cid)) {
                        \OpenSwoole\Coroutine::cancel($cid);
                    }
                });
                \Phalanx\Concurrency\Co::sleep($intervalUs / 1_000_000);
                $token->offCancel($key);
            }
            fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

            fprintf(STDERR, "\n--- B: WaitReason create + assign + null (no sleep) ---\n");
            $fakeRun = new stdClass();
            $fakeRun->currentWait = null;
            $base = memory_get_usage();
            for ($i = 0; $i < 500; $i++) {
                $reason = \Phalanx\Supervisor\WaitReason::delay($intervalUs / 1_000_000);
                $fakeRun->currentWait = $reason;
                $fakeRun->currentWait = null;
            }
            fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

            fprintf(STDERR, "\n--- C: full $" . "scope->delay() ---\n");
            $base = memory_get_usage();
            for ($i = 0; $i < 500; $i++) {
                $ctx->delay($intervalUs / 1_000_000);
            }
            fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

            if ($supervisor !== null && $currentRun !== null) {
                $ledger = $supervisor->ledger;
                $runId = $currentRun->id;

                fprintf(STDERR, "\n--- D: ledger beginWait + clearWait directly (no closure) ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $reason = \Phalanx\Supervisor\WaitReason::delay($intervalUs / 1_000_000);
                    $ledger->beginWait($runId, $reason);
                    $ledger->clearWait($runId);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- E: supervisor->beginWait + clearWait (full path) ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $reason = \Phalanx\Supervisor\WaitReason::delay($intervalUs / 1_000_000);
                    $clearWait = $supervisor->beginWait($currentRun, $reason);
                    $clearWait();
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- F: closure create + call only (no WaitReason, no ledger) ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $fn = static function () use ($ledger, $runId): void {
                        $ledger->clearWait($runId);
                    };
                    $fn();
                    unset($fn);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- G: WaitReason + ledger beginWait (no clear, no closure) ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $reason = \Phalanx\Supervisor\WaitReason::delay($intervalUs / 1_000_000);
                    $ledger->beginWait($runId, $reason);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- H: sprintf only (WaitReason label source) ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $s = sprintf('%.3fs', $intervalUs / 1_000_000);
                    unset($s);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- I: assertCanWait only ---\n");
                $base = memory_get_usage();
                $reason = \Phalanx\Supervisor\WaitReason::delay($intervalUs / 1_000_000);
                for ($i = 0; $i < 500; $i++) {
                    $supervisor->assertCanWait($currentRun, $reason);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- J: direct property toggle on real TaskRun (no ledger) ---\n");
                $reason = \Phalanx\Supervisor\WaitReason::delay($intervalUs / 1_000_000);
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $currentRun->currentWait = $reason;
                    $currentRun->state = \Phalanx\Supervisor\RunState::Suspended;
                    $currentRun->currentWait = null;
                    $currentRun->state = \Phalanx\Supervisor\RunState::Running;
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- K: ledger beginWait + clearWait, reused WaitReason ---\n");
                $reason = \Phalanx\Supervisor\WaitReason::delay($intervalUs / 1_000_000);
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $ledger->beginWait($runId, $reason);
                    $ledger->clearWait($runId);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- L: new WaitReason each iter, assign to local + unset ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $r = \Phalanx\Supervisor\WaitReason::delay($intervalUs / 1_000_000);
                    unset($r);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- M: empty closure create + call + unset ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $fn = static function (): void {};
                    $fn();
                    unset($fn);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- N: closure capturing 2 vars, create + call + unset ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $fn = static function () use ($ledger, $runId): void {};
                    $fn();
                    unset($fn);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- O: ledger clearWait as no-op (state already Running) ---\n");
                $ledger->clearWait($runId);
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $ledger->clearWait($runId);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- P: hash lookup only via ledger->find() ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $ledger->find($runId);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- Q: beginWait only, reused WaitReason (no clear) ---\n");
                $reason = \Phalanx\Supervisor\WaitReason::delay($intervalUs / 1_000_000);
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $ledger->beginWait($runId, $reason);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);

                fprintf(STDERR, "\n--- R: clearWait only, after Q left state Suspended ---\n");
                $base = memory_get_usage();
                for ($i = 0; $i < 500; $i++) {
                    $ledger->clearWait($runId);
                }
                fprintf(STDERR, "500 calls: %+dB (%+d/call)\n", memory_get_usage() - $base, (memory_get_usage() - $base) / 500);
            } else {
                fprintf(STDERR, "\nSKIP D-I: supervisor=%s currentRun=%s\n",
                    $supervisor !== null ? 'set' : 'null',
                    $currentRun !== null ? 'set' : 'null',
                );
            }
        })(),

        default => fprintf(STDERR, "Unknown test: %s\n", $test),
    };

    fprintf(STDERR, "\nFinal: %+dB growth over %d iterations\n", memory_get_usage() - $startMem, $iterations);

    return 0;
}, new CommandConfig(options: [
    Opt::value('test', desc: 'Test mode: delay, periodic, both, bare-sleep, token-churn'),
    Opt::value('n', desc: 'Number of iterations'),
    Opt::value('interval', desc: 'Interval in microseconds'),
]))->default('mem-diagnostic')->run(array_slice($_SERVER['argv'] ?? [], 1)));
