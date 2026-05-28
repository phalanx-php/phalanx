<?php

declare(strict_types=1);

namespace Phalanx\Poc\ProcessClaims;

use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;
use Symfony\Component\Process\Process;

require __DIR__ . '/../bench.php';

Clock::start();
Logger::open(__DIR__ . '/../results/03-symfony-process-hooked.txt');
Logger::header('Test 3: Symfony Process under OpenSwoole runtime hooks');

Logger::line('Claim: Symfony Process becomes non-blocking under runtime hooks because');
Logger::line('  its internal stream_select() loop and pipe reads get hooked. If true,');
Logger::line('  we can drop the custom Phalanx StreamingProcess and adopt Symfony Process');
Logger::line('  as the sidecar primitive — the single biggest win on the table.');
Logger::line('');
Logger::line('Method: spawn a long-running command via Symfony Process inside go();');
Logger::line('  iterate getIterator() while a sibling ticker increments every 25ms;');
Logger::line('  total wall time should approximate the longer of (process, ticker).');
Logger::line('');

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Coroutine::run(static function (): void {
    $sibling = new Sibling();

    Coroutine::create(static function () use ($sibling): void {
        for ($i = 0; $i < 80; $i++) {
            $sibling->record();
            usleep(25_000);
        }
    });

    Coroutine::create(static function (): void {
        Logger::line('--- Variant A: Process->getIterator() (incremental) ---');
        $proc = Process::fromShellCommandline('for i in 1 2 3 4 5 6 7 8; do echo "stream-line-$i"; sleep 0.1; done');
        $proc->setTimeout(10.0);
        $started = Clock::ms();
        $proc->start();
        $linesSeen = 0;
        $linesAtMs = [];
        foreach ($proc as $type => $data) {
            $chunkLines = substr_count((string) $data, "\n");
            $linesSeen += $chunkLines;
            $linesAtMs[] = Clock::ms();
        }
        $exit = $proc->getExitCode();
        $elapsed = Clock::ms() - $started;
        Logger::line(sprintf(
            '  iterator yielded %d lines, exit=%s, elapsed=%.1fms (expected ~800ms)',
            $linesSeen,
            $exit === null ? 'null' : (string) $exit,
            $elapsed,
        ));
        if (count($linesAtMs) >= 2) {
            $first = $linesAtMs[0] - $started;
            $last = end($linesAtMs) - $started;
            Logger::line(sprintf(
                '  first line at +%.0fms, last line at +%.0fms (incremental delivery proves yielding)',
                $first,
                $last,
            ));
        }
    });

    Coroutine::create(static function (): void {
        Logger::line('--- Variant B: Process->run() (whole-output) ---');
        $proc = new Process(['/bin/sh', '-c', 'sleep 0.3; echo whole']);
        $proc->setTimeout(5.0);
        $started = Clock::ms();
        $proc->run();
        $elapsed = Clock::ms() - $started;
        Logger::line(sprintf(
            '  run() exit=%d, output=%s, elapsed=%.1fms (expected ~300ms)',
            $proc->getExitCode() ?? -1,
            trim($proc->getOutput()),
            $elapsed,
        ));
    });

    Coroutine::create(static function (): void {
        Logger::line('--- Variant C: Process->wait() with output callback ---');
        $proc = new Process(['/bin/sh', '-c', 'echo a; sleep 0.2; echo b; sleep 0.2; echo c']);
        $proc->setTimeout(5.0);
        $started = Clock::ms();
        $proc->start();
        $callbackTimes = [];
        $proc->wait(static function (string $type, string $buffer) use (&$callbackTimes, $started): void {
            foreach (preg_split('/\R/', trim($buffer)) ?: [] as $line) {
                if ($line !== '') {
                    $callbackTimes[] = Clock::ms() - $started;
                }
            }
        });
        $elapsed = Clock::ms() - $started;
        Logger::line(sprintf(
            '  wait(callback) exit=%d, callbacks at [%s] ms, elapsed=%.1fms',
            $proc->getExitCode() ?? -1,
            implode(', ', array_map(static fn(float $t): string => sprintf('%.0f', $t), $callbackTimes)),
            $elapsed,
        ));
    });

    while (count($sibling->tickAtMs) < 80) {
        usleep(50_000);
    }

    Logger::line('');
    $totalMs = end($sibling->tickAtMs) - $sibling->tickAtMs[0];
    Logger::line(sprintf(
        'sibling ticker: %d ticks, max gap %.1fms, total %.1fms (baseline 2000ms)',
        $sibling->ticks,
        $sibling->maxGapMs(),
        $totalMs,
    ));

    $verdict = $sibling->maxGapMs() < 60.0 && $totalMs < 2400.0
        ? 'PROVEN. Symfony Process yielded under hooks; the ticker stayed crisp.'
        : sprintf('CONDITIONAL. max-gap=%.0fms, total=%.0fms — review which variant stalled.', $sibling->maxGapMs(), $totalMs);
    Logger::line('VERDICT: ' . $verdict);
});
