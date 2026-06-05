<?php

declare(strict_types=1);

namespace Phalanx\Agents\Mcp;

final class McpServer
{
    private function __construct(
        private(set) string $name,
        private(set) Transport $transport,
        private(set) string $endpoint,
        /** @var array<string, string> */
        private(set) array $env = [],
    ) {
    }

    /** @param array<string, string> $env */
    public static function stdio(string $name, string $command, array $env = []): self
    {
        return new self($name, Transport::Stdio, $command, $env);
    }

    public static function sse(string $name, string $url): self
    {
        return new self($name, Transport::Sse, $url);
    }
}
