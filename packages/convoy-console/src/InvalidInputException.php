<?php

declare(strict_types=1);

namespace Convoy\Console;

use RuntimeException;

final class InvalidInputException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?CommandConfig $config = null,
    ) {
        parent::__construct($message);
    }

    public function withConfig(CommandConfig $config): self
    {
        return new self($this->getMessage(), $config);
    }
}
