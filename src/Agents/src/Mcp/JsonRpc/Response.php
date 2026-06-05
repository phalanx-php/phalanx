<?php

declare(strict_types=1);

namespace Phalanx\Agents\Mcp\JsonRpc;

final class Response
{
    public bool $isError {
        get => $this->error !== null;
    }

    /** @param array<string, mixed>|null $error */
    public function __construct(
        private(set) int|string $id,
        private(set) mixed $result = null,
        private(set) ?array $error = null,
    ) {
    }

    public static function decode(string $line): self
    {
        $data = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON-RPC response: not an object');
        }

        if (($data['jsonrpc'] ?? null) !== '2.0') {
            throw new \RuntimeException('Invalid JSON-RPC response: missing or incorrect jsonrpc version');
        }

        if (!array_key_exists('id', $data)) {
            throw new \RuntimeException('Invalid JSON-RPC response: missing id');
        }

        $id = $data['id'];
        if (!is_int($id) && !is_string($id)) {
            throw new \RuntimeException('Invalid JSON-RPC response: id must be int or string');
        }

        $error = isset($data['error']) && is_array($data['error']) ? $data['error'] : null;
        $result = array_key_exists('result', $data) ? $data['result'] : null;

        return new self($id, $result, $error);
    }
}
