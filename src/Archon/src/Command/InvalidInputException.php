<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use RuntimeException;

/**
 * Thrown by ArgvParser/InputValidator when argv cannot be reconciled with
 * a CommandConfig (missing required arg, unknown option, malformed value).
 * Carries the offending CommandConfig so the dispatcher can render the
 * matching help block alongside the error.
 */
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
