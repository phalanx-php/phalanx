<?php

declare(strict_types=1);

namespace Phalanx\Poc\ProcessClaims;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\System;
use OpenSwoole\Runtime;

require __DIR__ . '/../bench.php';

Clock::start();
Logger::open(__DIR__ . '/../results/02-proc-open-in-coroutine.txt');
Logger::header('Test 2: proc_open inside a coroutine, two read strategies');

Logger::line('Claim: proc_open is safe in coroutines because fork+exec replaces the cloned');
Logger::line('  PHP runtime, freeing the reactor/scheduler state in the child.');
Logger::line('Method: spawn the same long-ish command twice; once with raw stream_get_contents');
Logger::line('  under HOOK_PROC|HOOK_STREAM_FUNCTION, once with explicit System::waitEvent.');
Logger::line('  In both cases verify a sibling 100ms ticker keeps progressing.');
Logger::line('');

Runtime::enableCoroutine(SWOOLE_HOOK_PROC | SWOOLE_HOOK_STREAM_FUNCTION | SWOOLE_HOOK_SLEEP);

$workCmd = ['/bin/sh', '-c', 'for i in 1 2 3 4 5; do echo "line $i"; sleep 0.1; done'];

$runOnce = static function (string $label, array $argv, callable $reader): array {
    $pipes = [];
    $proc = proc_open(
        $argv,
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($proc)) {
        return ['label' => $label, 'lines' => 0, 'exit' => -1];
    }
    $startedAt = Clock::ms();
    $output = $reader($pipes[1]);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $exit = proc_close($proc);
    $elapsed = Clock::ms() - $startedAt;
    $lines = substr_count($output, "\n");
    Logger::line(sprintf('%s: %d lines, exit=%d, elapsed=%.1fms', $label, $lines, $exit, $elapsed));
    return ['label' => $label, 'lines' => $lines, 'exit' => $exit, 'elapsed' => $elapsed];
};

Coroutine::run(static function () use ($workCmd, $runOnce): void {
    $sibling = new Sibling();

    Coroutine::create(static function () use ($sibling): void {
        for ($i = 0; $i < 60; $i++) {
            $sibling->record();
            usleep(25_000);
        }
    });

    Coroutine::create(static function () use ($workCmd, $runOnce): void {
        $runOnce(
            '  hook+stream_get_contents',
            $workCmd,
            static function ($pipe): string {
                stream_set_blocking($pipe, true);
                return (string) stream_get_contents($pipe);
            }
        );
    });

    Coroutine::create(static function () use ($workCmd, $runOnce): void {
        $runOnce(
            '  System::waitEvent + fread',
            $workCmd,
            static function ($pipe): string {
                stream_set_blocking($pipe, false);
                $buf = '';
                while (true) {
                    $ready = System::waitEvent($pipe, SWOOLE_EVENT_READ, 5.0);
                    if ($ready === false) {
                        break;
                    }
                    $chunk = fread($pipe, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $buf .= $chunk;
                }
                return $buf;
            }
        );
    });

    while (!$sibling->ticks || count($sibling->tickAtMs) < 60) {
        usleep(50_000);
        if ($sibling->ticks >= 60) {
            break;
        }
    }

    Logger::line('');
    Logger::line(sprintf(
        'sibling ticker: %d ticks, max gap %.1fms, total %.1fms (baseline 1500ms)',
        $sibling->ticks,
        $sibling->maxGapMs(),
        end($sibling->tickAtMs) - $sibling->tickAtMs[0],
    ));
    Logger::line('Two 650ms procs overlapped a 1500ms ticker. If procs blocked the loop,');
    Logger::line('  total wall time would be ~2800ms. If they yielded, total ~1500-1700ms.');

    $totalMs = end($sibling->tickAtMs) - $sibling->tickAtMs[0];
    $verdict = $totalMs < 2000.0
        ? sprintf('PROVEN. Total %.0fms shows proc_open under hooks ran concurrent with ticker.', $totalMs)
        : sprintf('REFUTED. Total %.0fms suggests proc_open blocked the reactor.', $totalMs);
    Logger::line('VERDICT: ' . $verdict);
});
