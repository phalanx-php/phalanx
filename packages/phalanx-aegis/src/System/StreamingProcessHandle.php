<?php

declare(strict_types=1);

namespace Phalanx\System;

use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\Styx\Channel;
use Phalanx\System\Internal\SymfonyProcessAdapter;
use Phalanx\Task\TaskRun;

/**
 * Handle for a running StreamingProcess.
 *
 * This class now delegates all process lifecycle and I/O to the
 * SymfonyProcessAdapter while retaining the full Aegis resource,
 * cancellation, and diagnostic contract that downstream code depends on.
 *
 * Public API is unchanged from the previous implementation.
 */
final class StreamingProcessHandle
{
    public private(set) StreamingProcessState $state = StreamingProcessState::Running;

    private int $exitCode = -1;
    private int $signal = 0;
    private bool $released = false;
    private bool $stopped = false;
    private bool $killed = false;
    private readonly float $startedAt;

    public function __construct(
        private readonly SymfonyProcessAdapter $adapter,
        private readonly TaskScope&TaskExecutor $scope,
        private readonly string $resourceId,
        private readonly string $commandHead,
        private readonly int $pid,
        private readonly int $maxLineBytes,
    ) {
        $this->startedAt = microtime(true);
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

        // Timeout + WaitReason handling remains the responsibility of the caller
        // (ExecutionScope layer). The adapter performs the physical write.
        return $this->adapter->write($data);
    }

    public function getIncrementalOutput(): string
    {
        return $this->adapter->getIncrementalOutput();
    }

    public function getIncrementalErrorOutput(): string
    {
        return $this->adapter->getIncrementalErrorOutput();
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
        $this->scope->runtime->memory->resources->release($this->resourceId);
    }

    public function stop(float $timeout = 1.0, ?int $signal = null): void
    {
        $this->stopped = true;
        $this->state = StreamingProcessState::Stopped;

        $this->adapter->stop($timeout, $signal);
        $this->recordEvent(AegisEventSid::ProcessStopped, (string) ($signal ?? 15));
    }

    public function kill(): void
    {
        $this->killed = true;
        $this->state = StreamingProcessState::Killed;

        $this->adapter->stop(0.0, 9);
        $this->recordEvent(AegisEventSid::ProcessKilled, 'SIGKILL');
    }

    public function wait(): ?int
    {
        return $this->adapter->wait();
    }

    /**
     * Pipes incremental stdout from this process into the given Styx Channel.
     * The returned TaskRun is owned by the current scope and will be cleaned
     * up on scope disposal or when the process exits.
     */
    public function pipeToChannel(
        Channel $channel,
        ?callable $transformer = null
    ): TaskRun {
        $self = $this;

        // Debug logging directly in the target logic
        echo "[pipeToChannel] Starting reader task for pid=" . $this->pid . "\n";

        $reader = function (ExecutionScope $scope) use ($self, $channel, $transformer): void {
            echo "[pipeToChannel] Reader task started\n";

            while ($self->isRunning()) {
                $chunk = $self->getIncrementalOutput();
                if ($chunk !== '') {
                    $lines = $transformer ? $transformer($chunk) : explode("\n", $chunk);
                    foreach ($lines as $line) {
                        if ($line !== '') {
                            echo "[pipeToChannel] Emitting line: " . substr($line, 0, 50) . "\n";
                            $channel->emit($line);
                        }
                    }
                }

                // Cooperative yield
                $scope->call(static fn() => usleep(2_000));
            }

            // Final drain
            $chunk = $self->getIncrementalOutput();
            if ($chunk !== '') {
                $lines = $transformer ? $transformer($chunk) : explode("\n", $chunk);
                foreach ($lines as $line) {
                    if ($line !== '') {
                        $channel->emit($line);
                    }
                }
            }

            echo "[pipeToChannel] Reader finished. Completing channel.\n";
            $channel->complete();

            // Final cooperative yield
            $scope->call(static fn() => true);
        };

        return $this->scope->execute($reader);
    }

    private function recordEvent(AegisEventSid $eventId, string $data): void
    {
        $this->scope->runtime->memory->resources->recordEvent(
            $this->resourceId,
            $eventId,
            $data,
        );
    }
}
