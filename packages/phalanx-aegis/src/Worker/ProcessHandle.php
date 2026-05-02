<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use RuntimeException;

/**
 * Wraps a child process spawned via proc_open. Aegis enables OpenSwoole's
 * process and stream hooks as runtime substrate support for worker dispatch.
 *
 * OpenSwoole's native `OpenSwoole\Process` cannot fork while the runtime's
 * async-io thread pool is active (the runtime refuses with "unable to create
 * OpenSwoole\Process with async-io threads"). proc_open is the supported path
 * for forking under the managed runtime hook baseline.
 */
class ProcessHandle
{
    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private int $pid = 0;

    private string $readBuffer = '';

    public function __construct(
        private readonly string $workerScript,
        private readonly string $autoloadPath,
    ) {
    }

    public function start(): void
    {
        if ($this->process !== null) {
            return;
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([PHP_BINARY, $this->workerScript, $this->autoloadPath], $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('ProcessHandle: proc_open failed');
        }
        $this->process = $proc;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        $status = proc_get_status($proc);
        $this->pid = (int) $status['pid'];

        // Substrate finding (Phase 8): blocking fread on a proc_open pipe does NOT
        // yield to the OpenSwoole 26 coroutine scheduler. Even with process hooks,
        // pipe-side reads stall the scheduler when the child takes >0ms to respond.
        // We set non-blocking + use stream_select for the wait — that yields cleanly.
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    public function write(string $bytes): void
    {
        if ($this->stdin === null) {
            throw new RuntimeException('ProcessHandle: not started');
        }
        $remaining = $bytes;
        while ($remaining !== '') {
            $written = fwrite($this->stdin, $remaining);
            if ($written === false || $written === 0) {
                throw new RuntimeException('ProcessHandle: write failed');
            }
            $remaining = substr($remaining, $written);
        }
        fflush($this->stdin);
    }

    public function readLine(): string|false
    {
        if ($this->stdout === null) {
            throw new RuntimeException('ProcessHandle: not started');
        }
        $stdout = $this->stdout;
        while (true) {
            $newlinePos = strpos($this->readBuffer, "\n");
            if ($newlinePos !== false) {
                $line = substr($this->readBuffer, 0, $newlinePos);
                $this->readBuffer = substr($this->readBuffer, $newlinePos + 1);
                return $line;
            }
            // Wait for data via stream_select so the calling coroutine yields
            // instead of blocking the scheduler.
            $r = [$stdout];
            $w = null;
            $e = null;
            $ready = @stream_select($r, $w, $e, 1, 0);
            if ($ready === false) {
                return false;
            }
            if ($ready === 0) {
                // Timeout: no data this tick. Check for EOF, then loop.
                if (feof($stdout)) {
                    return false;
                }
                continue;
            }
            $chunk = fread($stdout, 8192);
            if ($chunk === false || ($chunk === '' && feof($stdout))) {
                return false;
            }
            if ($chunk === '') {
                continue;
            }
            $this->readBuffer .= $chunk;
        }
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function alive(): bool
    {
        if ($this->process === null) {
            return false;
        }
        $status = proc_get_status($this->process);
        return (bool) $status['running'];
    }

    public function kill(int $signal = SIGTERM): void
    {
        if ($this->process === null) {
            return;
        }
        if ($this->stdin !== null) {
            @fclose($this->stdin);
            $this->stdin = null;
        }
        proc_terminate($this->process, $signal);
    }
}
