<?php

declare(strict_types=1);

namespace Phalanx\Agents\Mcp\JsonRpc;

final class Request
{
    /** @param array<string, mixed> $params */
    public function __construct(
        private(set) int|string $id,
        private(set) string $method,
        private(set) array $params = [],
    ) {
    }

    public function encode(): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $this->id,
            'method' => $this->method,
            'params' => $this->params,
        ], JSON_THROW_ON_ERROR) . "\n";
    }
}
