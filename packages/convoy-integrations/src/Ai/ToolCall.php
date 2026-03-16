<?php

declare(strict_types=1);

namespace Convoy\Integration\Ai;

final class ToolCall
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public private(set) string $id,
        public private(set) string $name,
        public private(set) array $input,
    ) {}
}
