<?php

declare(strict_types=1);

namespace Phalanx\Ai\Schema;

final class StructuredOutputException extends \RuntimeException
{
    /** @param list<string> $validationErrors */
    public function __construct(
        string $message,
        public readonly string $rawOutput,
        public readonly array $validationErrors = [],
    ) {
        parent::__construct($message);
    }
}
