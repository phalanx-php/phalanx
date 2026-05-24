<?php

declare(strict_types=1);

namespace Phalanx\Enigma;

final class CommandResult
{
    public bool $successful {
        get => $this->exitCode === 0;
    }

    /** @var list<string> */
    public array $lines {
        get => explode("\n", rtrim($this->stdout, "\n"));
    }

    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $durationMs,
    ) {
    }

    /**
     * @return $this
     * @throws Exception\SshException
     */
    public function throwIfFailed(): self
    {
        if (!$this->successful) {
            throw new Exception\SshException(
                "SSH command failed (exit {$this->exitCode}): {$this->stderr}",
                $this->exitCode,
                $this->stderr,
            );
        }

        return $this;
    }
}
