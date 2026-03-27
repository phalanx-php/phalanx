<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Psr\Http\Message\ServerRequestInterface;

final readonly class RequestBody
{
    /**
     * @param array<string, mixed> $values Eagerly-decoded body (empty when body is not JSON)
     */
    private function __construct(
        private string $raw,
        private array $values,
    ) {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $raw = (string) $request->getBody();

        if ($raw === '') {
            return new self($raw, []);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self($raw, []);
        }

        return new self($raw, is_array($decoded) ? $decoded : []);
    }

    /**
     * Decode the raw body as JSON with caller-controlled flags.
     *
     * Follows the WsMessage::json() pattern -- throws on invalid JSON.
     */
    public function json(bool $assoc = true, int $flags = 0): mixed
    {
        return json_decode($this->raw, $assoc, 512, $flags | JSON_THROW_ON_ERROR);
    }

    public function text(): string
    {
        return $this->raw;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function int(string $key, ?int $default = null): ?int
    {
        if (!isset($this->values[$key])) {
            return $default;
        }

        return (int) $this->values[$key];
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!isset($this->values[$key])) {
            return $default;
        }

        return filter_var($this->values[$key], FILTER_VALIDATE_BOOLEAN);
    }

    public function string(string $key, string $default = ''): string
    {
        if (!isset($this->values[$key])) {
            return $default;
        }

        return (string) $this->values[$key];
    }

    /** @throws \RuntimeException */
    public function required(string $key): mixed
    {
        if (!$this->has($key)) {
            throw new \RuntimeException("Missing required body parameter: {$key}");
        }

        return $this->values[$key];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
