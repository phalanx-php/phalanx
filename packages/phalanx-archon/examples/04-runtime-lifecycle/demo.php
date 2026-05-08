<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use OpenSwoole\Coroutine;
use OpenSwoole\Process;

return static function (array $context): \Closure {
    $runnerPath = __DIR__ . '/runner.php';

    return static function () use ($runnerPath): int {
        $indentLines = static function (string $text): string {
            $lines = explode("\n", $text);
            foreach ($lines as &$line) {
                $line = '      ' . $line;
            }
            return implode("\n", $lines);
        };

        $check = static function (string $label, bool $passed): bool {
            printf("%s  %s\n", $passed ? '  ok    ' : '  failed', $label);
            return $passed;
        };

        /**
         * @param list<string>                          $argv
         * @param ?callable(Process, int, string): bool $onChunk fires after each read; return true to stop watching.
         */
        $runRunner = static function (
            array $argv,
            ?callable $onChunk = null,
            string $doneMarker = '',
            float $timeout = 5.0,
        ) use ($runnerPath): string {
            $proc = new Process(static function (Process $worker) use ($runnerPath, $argv): void {
                $worker->exec(PHP_BINARY, [$runnerPath, ...$argv]);
            }, true, 1, true);

            $pid = $proc->start();
            if ($pid === false) {
                return '';
            }
            $proc->setBlocking(false);

            $captured = '';
            Coroutine::run(static function () use ($proc, $pid, &$captured, $onChunk, $doneMarker, $timeout): void {
                $deadline = microtime(true) + $timeout;
                $signalled = false;
                while (microtime(true) < $deadline) {
                    $chunk = @$proc->read(8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $captured .= $chunk;
                        if (!$signalled && $onChunk !== null && $onChunk($proc, $pid, $captured)) {
                            $signalled = true;
                        }
                        if ($doneMarker !== '' && str_contains($captured, $doneMarker)) {
                            break;
                        }
                    }
                    Coroutine::usleep(20_000);
                }
            });

            Process::wait(true);
            while (true) {
                $chunk = @$proc->read(8192);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $captured .= $chunk;
            }

            return $captured;
        };

        /**
         * Scenario A: spawn the runner via OpenSwoole\Process, let workers tick,
         * send SIGINT, assert the scope's onDispose cleanup banner shows up before
         * the child exits.
         */
        $scenarioSigintSubprocess = static function () use ($runRunner, $check, $indentLines): bool {
            echo "scenario A — SIGINT propagates through subprocess\n";

            $captured = $runRunner(
                ['watch', '--duration=30'],
                static function (Process $proc, int $pid, string $captured): bool {
                    if (str_contains($captured, '[tick 1 1]')) {
                        Process::kill($pid, SIGINT);
                        return true;
                    }
                    return false;
                },
                doneMarker: '[cleanup:',
            );

            $passed = true;
            $passed = $check('  child opened resource', str_contains($captured, '[opened resource #1]')) && $passed;
            $passed = $check('  child emitted at least 1 tick', str_contains($captured, '[tick 1 1]')) && $passed;
            $passed = $check(
                '  scope onDispose cleanup ran',
                str_contains($captured, '[cleanup: closed resource #1]'),
            ) && $passed;

            if (!$passed) {
                echo "  captured output:\n" . $indentLines($captured) . "\n";
            }

            echo "\n";
            return $passed;
        };

        /**
         * Scenario B: --fail-worker=2 in a subprocess. The body completes normally
         * because $scope->go's per-task error boundary catches the throw, the other
         * two workers continue ticking, and onDispose runs at scope teardown.
         */
        $scenarioWorkerFailureIsolated = static function () use ($runRunner, $check, $indentLines): bool {
            echo "scenario B — single worker failure does not abort the batch\n";

            $captured = $runRunner(['watch', '--duration=0.4', '--fail-worker=2'], doneMarker: '[completed normally]');

            $passed = true;
            $passed = $check(
                '  workers 1 and 3 ticked',
                str_contains($captured, '[tick 1 1]') && str_contains($captured, '[tick 3 1]'),
            ) && $passed;
            $passed = $check(
                '  resource opened and cleaned up',
                str_contains($captured, '[opened resource #')
                    && str_contains($captured, '[cleanup: closed resource #'),
            ) && $passed;
            $passed = $check('  body completed normally', str_contains($captured, '[completed normally]')) && $passed;

            if (!$passed) {
                echo "  captured output:\n" . $indentLines($captured) . "\n";
            }

            echo "\n";
            return $passed;
        };

        /**
         * Scenario C: subprocess again, but this time the parent sends SIGTERM
         * (mapped to ConsoleSignalPolicy::default's exit-143). Demonstrates that
         * the same trap handles both SIGINT and SIGTERM and that the body sees a
         * Cancelled — distinct exit-code from scenario A's SIGINT.
         */
        $scenarioSignalCancellation = static function () use ($runRunner, $check, $indentLines): bool {
            echo "scenario C — SIGTERM cancels through the same trap\n";

            $captured = $runRunner(
                ['watch', '--duration=30'],
                static function (Process $proc, int $pid, string $captured): bool {
                    if (str_contains($captured, '[tick 1 1]')) {
                        Process::kill($pid, SIGTERM);
                        return true;
                    }
                    return false;
                },
                doneMarker: '[cleanup:',
            );

            $passed = true;
            $passed = $check('  workers ran before cancel', str_contains($captured, '[tick 1 1]')) && $passed;
            $passed = $check('  cancelled message reached body', str_contains($captured, '[cancelled:')) && $passed;
            $passed = $check(
                '  scope onDispose cleanup ran',
                str_contains($captured, '[cleanup: closed resource #'),
            ) && $passed;

            if (!$passed) {
                echo "  captured output:\n" . $indentLines($captured) . "\n";
            }

            echo "\n";
            return $passed;
        };

        echo "Phalanx Archon — Runtime Lifecycle\n\n";

        $failed = false;

        $failed = !$scenarioSigintSubprocess()      || $failed;
        $failed = !$scenarioWorkerFailureIsolated() || $failed;
        $failed = !$scenarioSignalCancellation()    || $failed;

        echo $failed ? "\nFAIL lifecycle\n" : "\nOK lifecycle\n";

        return $failed ? 1 : 0;
    };
};
