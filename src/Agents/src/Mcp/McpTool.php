<?php

declare(strict_types=1);

namespace Phalanx\Agents\Mcp;

final class McpTool
{
    public function __construct(
        private(set) string $name,
        private(set) string $description,
        /** @var array<string, mixed> */
        private(set) array $inputSchema,
        private(set) string $serverName,
    ) {
    }
}
