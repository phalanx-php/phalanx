<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use RuntimeException;

/** @internal */
final class UnknownCommand extends RuntimeException
{
    public string $command {
        get => $this->commandName;
    }

    private function __construct(private readonly string $commandName)
    {
        parent::__construct("Command not found: $commandName");
    }

    public static function named(string $command): self
    {
        return new self($command);
    }
}
