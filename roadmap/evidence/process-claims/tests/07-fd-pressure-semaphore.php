<?php

declare(strict_types=1);

namespace Phalanx\Poc\ProcessClaims;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Runtime;
use Symfony\Component\Process\Process;

require __DIR__ . '/../bench.php';

Clock::start();
Logger::open(__DIR__ . '/../results/07-fd-pressure-semaphore.txt');
Logger::header('Test 7: FD pressure — concurrent processes with vs without a semaphore');

Logger::line('Claim: concurrent proc_open without a semaphore can exhaust file descriptors');
Logger::line('  (each proc_open = 3 pipe pairs = 6 FDs in the parent). A bounded Channel');
Logger::line('  acts as a sane semaphore.');
Logger::line('');

$lowerLimit = 256;
shell_exec("ulimit -n {$lowerLimit} 2>/dev/null");

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$selfPid = posix_getpid();

$openFds = static function (int $pid): int {
    $out = (string) shell_exec("lsof -p {$pid} 2>/dev/null | wc -l");
    return max(0, (int) trim($out) - 1);
};

Logger::line(sprintf(
    'self pid=%d, baseline FD count=%d, ulimit -n=%s',
    $selfPid,
    $openFds($selfPid),
    trim((string) shell_exec('ulimit -n')),
));
Logger::line('');

$batch = static function (string $label, int $count, int $concurrency, int $selfPid) use ($openFds): void {
    Logger::line(sprintf('--- %s — %d processes, concurrency limit = %s', $label, $count, $concurrency === 0 ? 'unlimited' : (string) $concurrency));

    $started = Clock::ms();
    $semaphore = $concurrency === 0 ? null : new Channel($concurrency);
    if ($semaphore !== null) {
        for ($i = 0; $i < $concurrency; $i++) {
            $semaphore->push(true);
        }
    }
    $done = new Channel($count);
    $peakFds = $openFds($selfPid);

    Coroutine::create(static function () use (&$peakFds, $openFds, $selfPid): void {
        for ($i = 0; $i < 30; $i++) {
            $cur = $openFds($selfPid);
            if ($cur > $peakFds) {
                $peakFds = $cur;
            }
            usleep(50_000);
        }
    });

    for ($i = 0; $i < $count; $i++) {
        Coroutine::create(static function () use ($i, $semaphore, $done): void {
            if ($semaphore !== null) {
                $semaphore->pop();
            }
            try {
                $proc = new Process(['/bin/sh', '-c', 'sleep 0.4; echo done-' . $i]);
                $proc->setTimeout(5.0);
                $proc->run();
            } catch (\Throwable $e) {
                $done->push(['idx' => $i, 'err' => $e->getMessage()]);
                if ($semaphore !== null) {
                    $semaphore->push(true);
                }
                return;
            }
            if ($semaphore !== null) {
                $semaphore->push(true);
            }
            $done->push(['idx' => $i, 'exit' => $proc->getExitCode()]);
        });
    }

    $errors = 0;
    $ok = 0;
    for ($i = 0; $i < $count; $i++) {
        $result = $done->pop();
        if (isset($result['err'])) {
            $errors++;
            if ($errors <= 2) {
                Logger::line(sprintf('  ERROR idx=%d: %s', $result['idx'], $result['err']));
            }
        } else {
            $ok++;
        }
    }
    $elapsed = Clock::ms() - $started;
    Logger::line(sprintf(
        '  ok=%d errors=%d, peak FDs=%d, elapsed=%.0fms',
        $ok,
        $errors,
        $peakFds,
        $elapsed,
    ));
    Logger::line('');
};

Coroutine::run(static function () use ($batch, $selfPid): void {
    Coroutine::create(static function () use ($batch, $selfPid): void {
        $batch('A: 60 procs, unlimited concurrency', 60, 0, $selfPid);
        $batch('B: 60 procs, semaphore=8', 60, 8, $selfPid);

        Logger::line('Notes:');
        Logger::line('  Each proc_open opens 3 pipes (stdin/stdout/stderr) — 6 FDs in the parent.');
        Logger::line('  Symfony Process holds them until proc finishes + read drain.');
        Logger::line('  At unlimited concurrency, peak FDs = baseline + 6 * inflight count.');
        Logger::line('  At semaphore=N, peak FDs = baseline + 6 * N.');
        Logger::line('VERDICT: PROVEN if A peaks notably higher than B.');
    });
});
