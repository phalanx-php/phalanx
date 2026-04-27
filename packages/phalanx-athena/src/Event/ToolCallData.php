<?php

declare(strict_types=1);

namespace Phalanx\Athena\Event;

final readonly class ToolCallData
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $callId,
        public string $toolName,
        public array $arguments = [],
    ) {}
}
