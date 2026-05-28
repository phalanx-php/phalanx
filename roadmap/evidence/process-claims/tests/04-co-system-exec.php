<?php

declare(strict_types=1);

namespace Phalanx\Poc\ProcessClaims;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\System;
use OpenSwoole\Runtime;

require __DIR__ . '/../bench.php';

Clock::start();
Logger::open(__DIR__ . '/../results/04-co-system-exec.txt');
Logger::header('Test 4: OpenSwoole\\Coroutine\\System::exec for one-shot commands');

Logger::line('Claim: System::exec is the first-class native async helper for quick');
Logger::line('  commands that return whole output. Returns ["output" => string,');
Logger::line('  "code" => int, "signal" => int]. No pipe management needed.');
Logger::line('');
Logger::line('Method A: 8 sequential System::exec calls (200ms each) inside one coroutine');
Logger::line('Method B: 8 parallel coroutines each invoking System::exec');
Logger::line('Confirm B is roughly the cost of one call, not eight.');
Logger::line('');

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Coroutine::run(static function (): void {
    Coroutine::create(static function (): void {
        $started = Clock::ms();
        $results = [];
        for ($i = 1; $i <= 8; $i++) {
            $r = System::exec("/bin/sh -c 'sleep 0.2; echo seq-{$i}'");
            $results[] = trim((string) $r['output']);
        }
        $elapsed = Clock::ms() - $started;
        Logger::line(sprintf(
            'sequential: %d outputs in %.0fms (expected ~1600ms)',
            count($results),
            $elapsed,
        ));
        Logger::line('  outputs: ' . implode(', ', $results));
    });

    Coroutine::create(static function (): void {
        $started = Clock::ms();
        $results = [];
        $done = new \OpenSwoole\Coroutine\Channel(8);
        for ($i = 1; $i <= 8; $i++) {
            Coroutine::create(static function () use ($i, &$results, $done): void {
                $r = System::exec("/bin/sh -c 'sleep 0.2; echo par-{$i}'");
                $results[] = trim((string) $r['output']);
                $done->push(true);
            });
        }
        for ($i = 0; $i < 8; $i++) {
            $done->pop();
        }
        sort($results);
        $elapsed = Clock::ms() - $started;
        Logger::line(sprintf(
            'parallel: %d outputs in %.0fms (expected ~200-300ms; sequential would be ~1600ms)',
            count($results),
            $elapsed,
        ));
        Logger::line('  outputs: ' . implode(', ', $results));

        $verdict = $elapsed < 500.0
            ? 'PROVEN. System::exec yields the scheduler; 8 parallel calls cost ~1 call worth of wall time.'
            : sprintf('REFUTED. Parallel run took %.0fms — calls are not actually concurrent.', $elapsed);
        Logger::line('VERDICT: ' . $verdict);
    });
});
