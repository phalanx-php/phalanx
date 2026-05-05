<?php

declare(strict_types=1);

namespace Phalanx\System;

use OpenSwoole\Coroutine\System;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Runtime\Identity\AegisAnnotationSid;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;
use Throwable;

class StreamingProcessHandle
{
    private const int PIPE_STDIN = 0;
    private const int PIPE_STDOUT = 1;
    private const int PIPE_STDERR = 2;
    private const int SIGNAL_KILL = 9;
    private const int SIGNAL_TERMINATE = 15;
    private const float STATUS_POLL_INTERVAL = 0.01;

    public private(set) StreamingProcessState $state = StreamingProcessState::Running;

    /** @var resource|null */
    private mixed $process;

    /** @var array<int, resource|null> */
    private array $pipes;

    /** @var array<int, string> */
    private array $buffers = [
        1 => '',
        2 => '',
    ];

    private int $exitCode = -1;

    private int $signal = 0;

    private bool $released = false;

    private bool $stopped = false;

    private bool $killed = false;

    private readonly float $startedAt;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    public function __construct(
        mixed $process,
        array $pipes,
        private readonly TaskScope&TaskExecutor $scope,
        private readonly string $resourceId,
        private readonly string $commandHead,
        private readonly int $pid,
        private readonly int $maxLineBytes,
    ) {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->startedAt = microtime(true);
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        $status = $this->status();
        return $status !== null && (bool) $status['running'];
    }

    public function write(string $data, ?float $timeout = null): int
    {
        if ($data === '') {
            return 0;
        }

        $pipe = $this->pipe(self::PIPE_STDIN, 'stdin');
        $expected = strlen($data);
        $deadline = self::deadline($timeout);
        $written = 0;

        while ($written < $expected) {
            $remaining = self::remaining($deadline);
            if ($remaining === 0.0) {
                $this->recordFailure(AegisEventSid::ProcessWriteFailed, 'timeout');
                throw StreamingProcessException::writeTimedOut($written, $expected);
            }

            $ready = $this->scope->call(
                static fn(): bool|int => System::waitEvent($pipe, SWOOLE_EVENT_WRITE, $remaining),
                WaitReason::process($this->commandHead, "stdin {$expected}B"),
            );
            if ($ready === false) {
                $this->recordFailure(AegisEventSid::ProcessWriteFailed, 'timeout');
                throw StreamingProcessException::writeTimedOut($written, $expected);
            }

            try {
                $chunk = fwrite($pipe, substr($data, $written));
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->recordFailure(AegisEventSid::ProcessWriteFailed, $e->getMessage());
                throw StreamingProcessException::writeFailed($e);
            }

            if ($chunk === false || $chunk === 0) {
                $this->recordFailure(AegisEventSid::ProcessWriteFailed, 'pipe write failed');
                throw StreamingProcessException::writeFailed();
            }

            $written += $chunk;
        }

        return $written;
    }

    public function writeLine(string $line, ?float $timeout = null): int
    {
        return $this->write($line . "\n", $timeout);
    }

    public function read(int $bytes = 8192, ?float $timeout = null): string
    {
        return $this->readBuffered(self::PIPE_STDOUT, 'stdout', $bytes, $timeout);
    }

    public function readLine(?float $timeout = null): string
    {
        return $this->readLineFrom(self::PIPE_STDOUT, 'stdout', $timeout);
    }

    public function readError(int $bytes = 8192, ?float $timeout = null): string
    {
        return $this->readBuffered(self::PIPE_STDERR, 'stderr', $bytes, $timeout);
    }

    public function closeInput(): void
    {
        $this->closePipe(self::PIPE_STDIN);
    }

    public function wait(?float $timeout = null): ?StreamingProcessExit
    {
        if ($this->process === null) {
            return $this->exit();
        }

        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while (true) {
            $status = $this->status();
            if ($status === null || !(bool) $status['running']) {
                return $this->finalize();
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }

            $sleep = $deadline === null
                ? self::STATUS_POLL_INTERVAL
                : min(self::STATUS_POLL_INTERVAL, max(0.001, $deadline - microtime(true)));
            $this->scope->delay($sleep);
        }
    }

    public function stop(float $gracefulTimeout = 2.0, float $forceTimeout = 5.0): StreamingProcessExit
    {
        if ($this->process === null) {
            return $this->exit();
        }

        $this->stopped = true;
        $this->transition(StreamingProcessState::Stopping);
        $this->closeInput();
        $this->terminate(self::SIGNAL_TERMINATE, AegisEventSid::ProcessStopped);

        $exit = $this->wait($gracefulTimeout);
        if ($exit !== null) {
            return $exit;
        }

        $this->kill();
        $exit = $this->wait($forceTimeout);
        if ($exit === null) {
            throw StreamingProcessException::stopTimedOut($forceTimeout);
        }

        return $exit;
    }

    public function kill(int $signal = self::SIGNAL_KILL): bool
    {
        $sent = $this->terminate($signal, AegisEventSid::ProcessKilled);
        if ($sent) {
            $this->killed = true;
            $this->transition(StreamingProcessState::Killed);
        }

        return $sent;
    }

    public function close(string $reason = 'closed'): void
    {
        if ($this->released) {
            return;
        }

        if ($this->process !== null && $this->isRunning()) {
            $this->kill();
        }

        $this->closeAllPipes();

        if ($this->process !== null) {
            $this->finalize($reason);
            return;
        }

        $this->release($reason);
    }

    private static function deadline(?float $timeout): ?float
    {
        return $timeout === null ? null : microtime(true) + max(0.0, $timeout);
    }

    private static function remaining(?float $deadline): float
    {
        if ($deadline === null) {
            return -1.0;
        }

        return max(0.0, $deadline - microtime(true));
    }

    private function readPipe(int $index, string $name, int $bytes, float $timeout): ?string
    {
        if ($bytes < 1) {
            return '';
        }

        $pipe = $this->pipe($index, $name);
        $ready = $this->scope->call(
            static fn(): bool|int => System::waitEvent($pipe, SWOOLE_EVENT_READ, $timeout),
            WaitReason::process($this->commandHead, $name),
        );
        if ($ready === false) {
            return null;
        }

        try {
            $chunk = fread($pipe, $bytes);
        } catch (Cancelled $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->recordFailure(AegisEventSid::ProcessReadFailed, $e->getMessage());
            throw StreamingProcessException::readFailed($name, $e);
        }

        if ($chunk === false) {
            $this->recordFailure(AegisEventSid::ProcessReadFailed, $name);
            throw StreamingProcessException::readFailed($name);
        }

        return $chunk;
    }

    private function readBuffered(int $index, string $name, int $bytes, ?float $timeout): string
    {
        if ($bytes < 1) {
            return '';
        }

        $buffer = $this->buffers[$index] ?? '';
        if ($buffer !== '') {
            $chunk = substr($buffer, 0, $bytes);
            $this->buffers[$index] = substr($buffer, strlen($chunk));
            return $chunk;
        }

        return $this->readPipe($index, $name, $bytes, self::remaining(self::deadline($timeout))) ?? '';
    }

    private function readLineFrom(int $index, string $name, ?float $timeout): string
    {
        $deadline = self::deadline($timeout);

        while (true) {
            $buffer = $this->buffers[$index] ?? '';
            $position = strpos($buffer, "\n");
            if ($position !== false) {
                $line = substr($buffer, 0, $position + 1);
                $this->buffers[$index] = substr($buffer, $position + 1);
                return $line;
            }

            $remaining = self::remaining($deadline);
            if ($remaining === 0.0) {
                return '';
            }

            $chunk = $this->readPipe($index, $name, 8192, $remaining);
            if ($chunk === null) {
                return '';
            }

            if ($chunk === '') {
                $line = $buffer;
                $this->buffers[$index] = '';
                return $line;
            }

            $this->buffers[$index] = $buffer . $chunk;
            if (strlen($this->buffers[$index]) > $this->maxLineBytes) {
                throw StreamingProcessException::lineTooLong($this->maxLineBytes);
            }
        }
    }

    /** @return resource */
    private function pipe(int $index, string $name): mixed
    {
        $pipe = $this->pipes[$index] ?? null;
        if (!is_resource($pipe)) {
            throw StreamingProcessException::pipeClosed($name);
        }

        return $pipe;
    }

    private function closePipe(int $index): void
    {
        $pipe = $this->pipes[$index] ?? null;
        if (is_resource($pipe)) {
            fclose($pipe);
        }
        $this->pipes[$index] = null;
    }

    private function closeAllPipes(): void
    {
        foreach (array_keys($this->pipes) as $index) {
            $this->closePipe((int) $index);
        }
    }

    private function terminate(int $signal, AegisEventSid $event): bool
    {
        if ($this->process === null) {
            return false;
        }

        $sent = proc_terminate($this->process, $signal);
        if ($sent) {
            $this->scope->runtime->memory->resources->recordEvent($this->resourceId, $event, (string) $signal);
        }

        return $sent;
    }

    /** @return array<string, mixed>|null */
    private function status(): ?array
    {
        if ($this->process === null) {
            return null;
        }

        $status = proc_get_status($this->process);

        if (!(bool) $status['running']) {
            $this->exitCode = (int) $status['exitcode'];
        }
        if ((bool) $status['signaled']) {
            $this->signal = (int) $status['termsig'];
        }

        return $status;
    }

    private function finalize(string $reason = 'exited'): StreamingProcessExit
    {
        $status = $this->status();
        $this->closeAllPipes();

        if ($this->process !== null) {
            $closeCode = proc_close($this->process);
            $this->process = null;
            if ($this->exitCode === -1 && $closeCode !== -1) {
                $this->exitCode = $closeCode;
            }
        }

        if (is_array($status) && (bool) $status['signaled']) {
            $this->signal = (int) $status['termsig'];
        }

        $this->scope->runtime->memory->resources->annotate(
            $this->resourceId,
            AegisAnnotationSid::ProcessExitCode,
            $this->exitCode,
        );
        $this->scope->runtime->memory->resources->annotate(
            $this->resourceId,
            AegisAnnotationSid::ProcessSignal,
            $this->signal,
        );
        $this->scope->runtime->memory->resources->recordEvent(
            $this->resourceId,
            AegisEventSid::ProcessExited,
            (string) $this->exitCode,
            (string) $this->signal,
        );
        $this->transition($this->killed ? StreamingProcessState::Killed : StreamingProcessState::Exited);

        $exit = $this->exit();
        $this->release($reason);

        return $exit;
    }

    private function exit(): StreamingProcessExit
    {
        return new StreamingProcessExit(
            pid: $this->pid,
            exitCode: $this->exitCode,
            signal: $this->signal,
            durationMs: (microtime(true) - $this->startedAt) * 1_000,
            stopped: $this->stopped,
            killed: $this->killed,
        );
    }

    private function transition(StreamingProcessState $state): void
    {
        if ($this->released) {
            $this->state = $state;
            return;
        }

        $this->state = $state;
        $this->scope->runtime->memory->resources->annotate(
            $this->resourceId,
            AegisAnnotationSid::ProcessState,
            $state->value,
        );
    }

    private function recordFailure(AegisEventSid $event, string $reason): void
    {
        $this->transition(StreamingProcessState::Failed);
        $this->scope->runtime->memory->resources->fail($this->resourceId, $reason);
        $this->scope->runtime->memory->resources->recordEvent($this->resourceId, $event, $reason);
    }

    private function release(string $reason): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->state = StreamingProcessState::Closed;
        $this->scope->runtime->memory->resources->close($this->resourceId, $reason);
        $this->scope->runtime->memory->resources->release($this->resourceId);
    }
}
