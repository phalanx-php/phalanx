<?php

declare(strict_types=1);

namespace Phalanx\Console\Command;

use RuntimeException;

/** @internal */
final class UnknownCommand extends RuntimeException
{
    private function __construct(private(set) string $command)
    {
        parent::__construct("Command not found: $this->command");
    }

    public static function named(string $command): self
    {
        return new self($command);
    }
}
