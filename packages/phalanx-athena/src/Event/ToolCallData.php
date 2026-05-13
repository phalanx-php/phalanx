<?php

declare(strict_types=1);

namespace Phalanx\Athena\Event;

final class ToolCallData
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        private(set) string $callId,
        private(set) string $toolName,
        private(set) array $arguments = [],
    ) {}
}
