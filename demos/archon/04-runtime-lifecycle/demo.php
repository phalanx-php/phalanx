<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use OpenSwoole\Coroutine;
use OpenSwoole\Process;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\Kit\DemoSubprocess;

return DemoReport::demo(
    'Archon Runtime Lifecycle',
    static function (DemoReport $report, AppContext $_context): void {
        $runnerPath = __DIR__ . '/runner.php';

        $runScenario = static function (
            array $argv,
            ?\Closure $onChunk = null,
            string $doneMarker = '',
            float $timeout = 5.0,
        ) use ($runnerPath): string {
            $proc = DemoSubprocess::capture(static function (Process $worker) use ($runnerPath, $argv): void {
                $worker->exec(PHP_BINARY, [$runnerPath, ...$argv]);
            });

            if ($proc === null) {
                return '';
            }

            $captured = '';
            Coroutine::run(static function () use ($proc, &$captured, $onChunk, $doneMarker, $timeout): void {
                $captured = $proc->readUntil($doneMarker, $timeout, $onChunk);
            });

            $proc->awaitExit();
            $captured .= $proc->drain();

            return $captured;
        };

        // Scenario A: SIGINT propagates through subprocess; scope onDispose runs.
        $captured = $runScenario(
            ['watch', '--duration=30'],
            static function (Process $_proc, int $pid, string $captured): bool {
                if (str_contains($captured, '[tick 1 1]')) {
                    Process::kill($pid, SIGINT);

                    return true;
                }

                return false;
            },
            doneMarker: '[cleanup:',
        );
        $report->record('A: child opened resource',           str_contains($captured, '[opened resource #1]'), $captured);
        $report->record('A: child emitted at least 1 tick',   str_contains($captured, '[tick 1 1]'),           $captured);
        $report->record('A: scope onDispose cleanup ran',     str_contains($captured, '[cleanup: closed resource #1]'), $captured);

        // Scenario B: --fail-worker=2; per-task error boundary catches the throw,
        // other workers continue, body completes normally.
        $captured = $runScenario(['watch', '--duration=0.4', '--fail-worker=2'], doneMarker: '[completed normally]');
        $report->record(
            'B: workers 1 and 3 ticked',
            str_contains($captured, '[tick 1 1]') && str_contains($captured, '[tick 3 1]'),
            $captured,
        );
        $report->record(
            'B: resource opened and cleaned up',
            str_contains($captured, '[opened resource #') && str_contains($captured, '[cleanup: closed resource #'),
            $captured,
        );
        $report->record('B: body completed normally', str_contains($captured, '[completed normally]'), $captured);

        // Scenario C: SIGTERM cancels through the same trap; body sees Cancelled.
        $captured = $runScenario(
            ['watch', '--duration=30'],
            static function (Process $_proc, int $pid, string $captured): bool {
                if (str_contains($captured, '[tick 1 1]')) {
                    Process::kill($pid, SIGTERM);

                    return true;
                }

                return false;
            },
            doneMarker: '[cleanup:',
        );
        $report->record('C: workers ran before cancel',         str_contains($captured, '[tick 1 1]'), $captured);
        $report->record('C: cancelled message reached body',    str_contains($captured, '[cancelled:'), $captured);
        $report->record('C: scope onDispose cleanup ran',       str_contains($captured, '[cleanup: closed resource #'), $captured);
    },
);
