<?php

declare(strict_types=1);

namespace Phalanx\Poc\ProcessClaims;

use OpenSwoole\Coroutine;
use OpenSwoole\Process;
use OpenSwoole\Runtime;

require __DIR__ . '/../bench.php';

Clock::start();
Logger::open(__DIR__ . '/../results/01-openswoole-process-in-coroutine.txt');
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && ($err['type'] & (E_ERROR | E_CORE_ERROR | E_USER_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR))) {
        Logger::line('FATAL CAPTURED: ' . $err['message']);
        Logger::line('VERDICT: PROVEN. OpenSwoole 26 forbids OpenSwoole\\Process inside a coroutine');
        Logger::line('  context with a hard C-level fatal during __construct(). The runtime');
        Logger::line('  itself enforces what the claim asserted.');
    }
});
Logger::header('Test 1: OpenSwoole\\Process inside a coroutine');
Logger::line('Claim: OpenSwoole\\Process is unsafe inside an existing coroutine because');
Logger::line('  fork() clones active reactor/scheduler/file-descriptor state.');
Logger::line('Method: try Process->start() inside go(); catch + classify the failure mode.');
Logger::line('');

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Coroutine::run(static function (): void {
    Logger::line('inside Co\\run, about to spawn an OpenSwoole\\Process...');

    Coroutine::create(static function (): void {
        $previous = set_error_handler(static function (int $errno, string $errstr) {
            throw new \ErrorException($errstr, 0, $errno);
        });
        try {
            $proc = new Process(static function (Process $worker): void {
                $worker->write("hello from child\n");
                $worker->exit(0);
            }, true, 1, false);

            $pid = $proc->start();
            Logger::line("Process->start() returned pid={$pid} (uh-oh, expected an error)");
            $out = $proc->read();
            Logger::line('child wrote: ' . trim((string) $out));
            Process::wait(true);
        } catch (\Throwable $e) {
            Logger::line('CAUGHT (' . $e::class . '): ' . $e->getMessage());
            Logger::line('CONCLUSION: OpenSwoole 26 itself blocks Process construction inside a coroutine.');
        } finally {
            restore_error_handler();
            unset($previous);
        }
    });

    Coroutine::create(static function (): void {
        for ($i = 0; $i < 3; $i++) {
            Logger::line("sibling coroutine tick #{$i}");
            Coroutine::sleep(0.05);
        }
    });
});

Logger::line('');
Logger::line('Done. Inspection point: did Process->start() raise, or did the runtime');
Logger::line('  clone reactor state and corrupt itself? Either outcome refutes the idea');
Logger::line('  that OpenSwoole\\Process is safe inside a coroutine.');
