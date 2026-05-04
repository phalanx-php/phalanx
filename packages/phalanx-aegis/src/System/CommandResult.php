<?php

declare(strict_types=1);

namespace Phalanx\System;

/**
 * Outcome of running an external command through SystemCommand.
 *
 * Combined output: stdout and stderr arrive together when the command was
 * dispatched with captureStderr=true. Otherwise stderr inherits the parent
 * process. The supervisor records exit code, signal, and duration; consumers
 * get a value object they can inspect or re-throw.
 */
final class CommandResult
{
    public bool $successful {
        get => $this->exitCode === 0;
    }

    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly float $durationMs,
        public readonly int $signal = 0,
    ) {
    }

    public function throwIfFailed(string $context = ''): void
    {
        if ($this->exitCode === 0) {
            return;
        }

        $head = $context !== ''
            ? "{$context}: command '{$this->command}' exited with code {$this->exitCode}"
            : "command '{$this->command}' exited with code {$this->exitCode}";

        if ($this->signal !== 0) {
            $head .= " (signal {$this->signal})";
        }

        $tail = trim($this->output);
        $message = $tail !== '' ? "{$head}: {$tail}" : $head;

        throw new SystemCommandException($message);
    }
}
