<?php

declare(strict_types=1);

namespace Phalanx\Demos\Kit;

use Closure;
use OpenSwoole\Coroutine;
use OpenSwoole\Process;

/**
 * Subprocess orchestration for demos. Replaces the Process+SIGTERM/SIGKILL
 * escalation block previously duplicated in stoa-realtime, stoa-runtime,
 * and archon-runtime-lifecycle.
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
 * Why OpenSwoole\Process and not Phalanx\System\StreamingProcess: demos
 * that spawn an inline-defined server via closure (stoa-03/04 wrap a
 * Stoa::starting()->...->run() body in a child without needing a
 * separate server.php file) can only use OpenSwoole\Process(Closure).
 * StreamingProcess is binary-exec only and requires a scope to register
 * the process as a managed resource — neither fits the demo-driver
 * lifecycle here (the parent has no Phalanx scope; the child is the
 * server). Once stoa server.php files exist, capture() can migrate to
 * StreamingProcess; spawn() will keep needing OpenSwoole\Process.
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

            Coroutine::usleep(20_000);
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
            usleep(20_000);
        } while (microtime(true) < $deadline);

        Process::kill($this->pid, SIGKILL);
        Process::wait(false);
    }
}
