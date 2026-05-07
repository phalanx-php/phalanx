<?php

declare(strict_types=1);

namespace Phalanx\System\Internal;

use LogicException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Thin adapter around Symfony Process that provides the low-level
 * streaming + control surface we need while letting Symfony handle
 * the cross-platform pipe, TTY, signal, and buffering complexity.
 *
 * This class is internal to Aegis and not part of the public API.
 * The public `StreamingProcess` / `StreamingProcessHandle` surface
 * remains unchanged.
 */
final class SymfonyProcessAdapter
{
    private Process $process;

    private ?InputStream $input = null;

    /**
     * @param non-empty-list<string> $argv
     * @param array<string, string>|null $env
     */
    public function __construct(
        private readonly array $argv,
        private readonly ?string $cwd,
        private readonly ?array $env,
    ) {
        $this->process = new Process(
            command: $this->argv,
            cwd: $this->cwd,
            env: $this->env,
            input: null,
            timeout: null, // we manage timeout at the handle layer
        );

        $this->process->setPty(false);
        $this->process->setIdleTimeout(null);
    }

    public function start(): void
    {
        $this->input = new InputStream();
        $this->process->setInput($this->input);
        $this->process->start();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function pid(): int
    {
        return $this->process->getPid() ?? 0;
    }

    public function write(string $data): int
    {
        if ($this->input === null) {
            throw new LogicException('Process not started or input closed');
        }

        $this->input->write($data);

        return strlen($data);
    }

    /**
     * Returns available stdout data without blocking.
     */
    public function getIncrementalOutput(): string
    {
        return $this->process->getIncrementalOutput();
    }

    /**
     * Returns available stderr data without blocking.
     */
    public function getIncrementalErrorOutput(): string
    {
        return $this->process->getIncrementalErrorOutput();
    }

    public function getExitCode(): ?int
    {
        if ($this->process->isRunning()) {
            return null;
        }

        return $this->process->getExitCode();
    }

    public function getTermSignal(): ?int
    {
        $signal = $this->process->getTermSignal();
        return $signal > 0 ? $signal : null;
    }

    /**
     * Wait for the process to finish (blocking).
     * This ensures all output is available via getIncrementalOutput().
     */
    public function wait(): int
    {
        return $this->process->wait();
    }

    /** Stop the process gracefully or forcefully. */
    public function stop(float $timeout = 1.0, ?int $signal = null): void
    {
        if ($signal !== null) {
            $this->process->signal($signal);
        }

        $this->process->stop($timeout, $signal);
        $this->closeInput();
    }

    public function close(): void
    {
        $this->closeInput();

        if ($this->process->isRunning()) {
            $this->process->stop(1.0, 9);
        }
    }

    private function closeInput(): void
    {
        if ($this->input !== null) {
            $this->input->close();
            $this->input = null;
        }
    }
}
