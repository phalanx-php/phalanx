<?php

declare(strict_types=1);

namespace Phalanx\System;

use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\Internal\SymfonyProcessAdapter;

/**
 * Handle for a running StreamingProcess.
 *
 * The Symfony process object stays behind this handle so Aegis can enforce
 * scope-owned cleanup before the owning coroutine runtime unwinds.
 */
final class StreamingProcessHandle
{
    public private(set) StreamingProcessState $state = StreamingProcessState::Running;

    private bool $released = false;
    private string $stderrBuffer = '';
    private string $stdoutBuffer = '';

    public function __construct(
        private readonly SymfonyProcessAdapter $adapter,
        private readonly TaskScope&TaskExecutor $scope,
        private readonly string $resourceId,
        private readonly int $pid,
        private readonly int $maxLineBytes,
    ) {
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        return $this->adapter->isRunning();
    }

    public function write(string $data, ?float $timeout = null): int
    {
        if ($data === '') {
            return 0;
        }

        return $this->adapter->write($data);
    }

    public function getIncrementalOutput(): string
    {
        $output = $this->stdoutBuffer . $this->adapter->getIncrementalOutput();
        $this->stdoutBuffer = '';

        return $output;
    }

    public function getIncrementalErrorOutput(): string
    {
        $output = $this->stderrBuffer . $this->adapter->getIncrementalErrorOutput();
        $this->stderrBuffer = '';

        return $output;
    }

    public function readLine(?float $timeout = null): string
    {
        $deadline = self::deadline($timeout);

        while (true) {
            $this->scope->throwIfCancelled();
            $this->drainStdout();

            $line = $this->shiftLine();
            if ($line !== null) {
                return $line;
            }

            if (!$this->isRunning()) {
                return $this->flushStdoutRemainder();
            }

            if (self::timedOut($deadline)) {
                return '';
            }

            $this->pause($deadline);
        }
    }

    public function readError(int $bytes = 8192, ?float $timeout = null): string
    {
        $deadline = self::deadline($timeout);
        $bytes = max(1, $bytes);

        while (true) {
            $this->scope->throwIfCancelled();
            $this->stderrBuffer .= $this->adapter->getIncrementalErrorOutput();

            if ($this->stderrBuffer !== '') {
                return $this->shiftError($bytes);
            }

            if (!$this->isRunning() || self::timedOut($deadline)) {
                return '';
            }

            $this->pause($deadline);
        }
    }

    public function close(string $reason = 'manual'): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->state = StreamingProcessState::Closed;

        $this->adapter->close();

        $this->recordEvent(AegisEventSid::ProcessExited, $reason);
        $this->releaseResource();
    }

    public function stop(float $gracefulTimeout = 1.0, float $forceTimeout = 0.0): void
    {
        if ($this->released) {
            return;
        }

        $this->state = StreamingProcessState::Exited;

        $this->adapter->stop($gracefulTimeout, $forceTimeout > 0.0 ? 9 : null);
        $this->recordEvent(AegisEventSid::ProcessStopped, 'SIGTERM');
        $this->releaseResource();
    }

    public function kill(): void
    {
        if ($this->released) {
            return;
        }

        $this->state = StreamingProcessState::Killed;

        $this->adapter->stop(0.0, 9);
        $this->recordEvent(AegisEventSid::ProcessKilled, 'SIGKILL');
        $this->releaseResource();
    }

    public function wait(?float $timeout = null): ?int
    {
        $deadline = self::deadline($timeout);
        while ($this->isRunning() && !self::timedOut($deadline)) {
            $this->scope->throwIfCancelled();
            $this->pause($deadline);
        }

        if ($this->isRunning()) {
            return null;
        }

        return $this->adapter->getExitCode() ?? $this->exitCodeFromSignal() ?? 0;
    }

    private static function deadline(?float $timeout): ?float
    {
        return $timeout === null ? null : microtime(true) + max(0.0, $timeout);
    }

    private static function timedOut(?float $deadline): bool
    {
        return $deadline !== null && microtime(true) >= $deadline;
    }

    private function recordEvent(AegisEventSid $eventId, string $data): void
    {
        $this->scope->runtime->memory->resources->recordEvent(
            $this->resourceId,
            $eventId,
            $data,
        );
    }

    private function drainStdout(): void
    {
        $this->stdoutBuffer .= $this->adapter->getIncrementalOutput();

        if (!str_contains($this->stdoutBuffer, "\n") && strlen($this->stdoutBuffer) > $this->maxLineBytes) {
            throw StreamingProcessException::lineTooLong($this->maxLineBytes);
        }
    }

    private function shiftLine(): ?string
    {
        $position = strpos($this->stdoutBuffer, "\n");
        if ($position === false) {
            return null;
        }

        $length = $position + 1;
        if ($length > $this->maxLineBytes) {
            throw StreamingProcessException::lineTooLong($this->maxLineBytes);
        }

        $line = substr($this->stdoutBuffer, 0, $length);
        $this->stdoutBuffer = substr($this->stdoutBuffer, $length);

        return $line;
    }

    private function flushStdoutRemainder(): string
    {
        if ($this->stdoutBuffer === '') {
            return '';
        }

        if (strlen($this->stdoutBuffer) > $this->maxLineBytes) {
            throw StreamingProcessException::lineTooLong($this->maxLineBytes);
        }

        $line = $this->stdoutBuffer;
        $this->stdoutBuffer = '';

        return $line;
    }

    private function shiftError(int $bytes): string
    {
        $chunk = substr($this->stderrBuffer, 0, $bytes);
        $this->stderrBuffer = substr($this->stderrBuffer, strlen($chunk));

        return $chunk;
    }

    private function exitCodeFromSignal(): ?int
    {
        $signal = $this->adapter->getTermSignal();
        return $signal === null ? null : 128 + $signal;
    }

    private function pause(?float $deadline): void
    {
        $delay = 0.001;
        if ($deadline !== null) {
            $delay = min($delay, max(0.0, $deadline - microtime(true)));
        }

        if ($delay > 0.0) {
            $this->scope->delay($delay);
        }
    }

    private function releaseResource(): void
    {
        $this->released = true;
        $this->scope->runtime->memory->resources->release($this->resourceId);
    }
}
