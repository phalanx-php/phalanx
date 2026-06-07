<?php

declare(strict_types=1);

namespace Phalanx\Demos\Kit;

use Closure;
use Phalanx\System\PhpExtensionFlags;
use Swoole\Coroutine;
use Swoole\Process;

/**
 * Subprocess orchestration for demos. Replaces the Process+SIGTERM/SIGKILL
 * escalation block previously duplicated in http-realtime, http-runtime,
 * and console-runtime-lifecycle.
 *
 * Two factory shapes:
 *   spawn() — child runs a long-lived service (HTTP server etc.); parent
 *             does not read its stdout.
 *   capture() — child stdout is piped back; parent drains via readUntil()
 *               and drain().
 *
 * In both shapes terminate() does graceful SIGTERM then escalates to
 * SIGKILL after $gracePeriod. awaitExit() waits for natural completion
 * (used by capture() flows that complete on their own).
 *
 * Why Swoole\Process and not Phalanx\System\StreamingProcess: demos
 * that spawn an inline-defined server via closure (http-03/04 wrap a
 * Http::starting()->...->run() body in a child without needing a
 * separate server.php file) can only use Swoole\Process(Closure).
 * StreamingProcess is binary-exec only and requires a scope to register
 * the process as a managed resource — neither fits the demo-driver
 * lifecycle here (the parent has no Phalanx scope; the child is the
 * server).
 */
final class DemoSubprocess
{
    private function __construct(
        private Process $process,
        private(set) int $pid,
    ) {
    }

    public static function spawn(Closure $worker): ?self
    {
        $process = new Process($worker);
        $pid = $process->start();

        return is_int($pid) ? new self($process, $pid) : null;
    }

    public static function capture(Closure $worker): ?self
    {
        $process = new Process($worker, true, 1, true);
        $pid = $process->start();
        if (!is_int($pid)) {
            return null;
        }
        $process->setBlocking(false);

        return new self($process, $pid);
    }

    /**
     * Build a {binary, args} tuple for $process->exec() that inherits the parent
     * process's loaded shared extensions. Required when an exec'd child boots a
     * Phalanx kernel that depends on extensions like swoole.
     *
     * @param list<string> $scriptArgs
     * @return array{0: string, 1: list<string>}
     */
    public static function phpCommand(string $scriptPath, array $scriptArgs = []): array
    {
        $args   = PhpExtensionFlags::forLoaded(['swoole', 'sqlite3']);
        $args[] = $scriptPath;
        foreach ($scriptArgs as $arg) {
            $args[] = $arg;
        }

        return [PHP_BINARY, $args];
    }

    public function send(int $signal): void
    {
        Process::kill($this->pid, $signal);
    }

    /**
     * Drain stdout until $doneMarker appears or $timeout elapses. Optional
     * $onChunk receives (Process, pid, captured) after each read; return
     * true to mark "signal sent" and skip the callback on subsequent
     * reads. Must run inside a Coroutine context.
     *
     * @param ?Closure(Process, int, string): bool $onChunk
     */
    public function readUntil(string $doneMarker = '', float $timeout = 5.0, ?Closure $onChunk = null): string
    {
        $captured = '';
        $deadline = microtime(true) + $timeout;
        $signalled = false;

        while (microtime(true) < $deadline) {
            $chunk = (string) @$this->process->read(8192);
            if ($chunk !== '') {
                $captured .= $chunk;

                if (!$signalled && $onChunk !== null && $onChunk($this->process, $this->pid, $captured)) {
                    $signalled = true;
                }

                if ($doneMarker !== '' && str_contains($captured, $doneMarker)) {
                    break;
                }
            }

            Coroutine::sleep(0.02);
        }

        return $captured;
    }

    public function drain(): string
    {
        $captured = '';
        while (true) {
            $chunk = (string) @$this->process->read(8192);
            if ($chunk === '') {
                break;
            }
            $captured .= $chunk;
        }

        return $captured;
    }

    public function awaitExit(): void
    {
        Process::wait(true);
    }

    /**
     * Graceful stop. SIGTERM, then SIGKILL if the process is still alive
     * after $gracePeriod seconds. Idempotent if the child already exited.
     */
    public function terminate(float $gracePeriod = 3.0): void
    {
        Process::kill($this->pid, SIGTERM);

        $deadline = microtime(true) + $gracePeriod;
        do {
            $status = Process::wait(false);
            if ($status !== false) {
                return;
            }
            self::sleep(0.02);
        } while (microtime(true) < $deadline);

        Process::kill($this->pid, SIGKILL);
        Process::wait(false);
    }

    private static function sleep(float $seconds): void
    {
        if (Coroutine::getCid() > 0) {
            Coroutine::sleep($seconds);
            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }
}
