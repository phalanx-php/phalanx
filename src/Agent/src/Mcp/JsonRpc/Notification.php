<?php

declare(strict_types=1);

namespace Phalanx\Agent\Mcp\JsonRpc;

final class Notification
{
    /** @param array<string, mixed> $params */
    public function __construct(
        private(set) string $method,
        private(set) array $params = [],
    ) {
    }

    public function encode(): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'method' => $this->method,
            'params' => $this->params,
        ], JSON_THROW_ON_ERROR) . "\n";
    }
}
