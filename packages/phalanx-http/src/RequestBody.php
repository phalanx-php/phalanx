<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Psr\Http\Message\ServerRequestInterface;

final class RequestBody
{
    /** @var array<string, true> */
    private array $validationCache = [];

    /**
     * @param array<string, mixed> $values Eagerly-decoded body (empty when body is not JSON)
     */
    private function __construct(
        private readonly string $raw,
        private readonly array $values,
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

    public function get(string $key, mixed $default = null, ?RequestValidator $validate = null): mixed
    {
        $value = $this->values[$key] ?? $default;

        if ($validate !== null && $value !== null) {
            $this->validate($key, $value, $validate);
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function int(string $key, ?int $default = null, ?RequestValidator $validate = null): ?int
    {
        if (!isset($this->values[$key])) {
            return $default;
        }

        $value = (int) $this->values[$key];

        if ($validate !== null) {
            $this->validate($key, $value, $validate);
        }

        return $value;
    }

    public function bool(string $key, bool $default = false, ?RequestValidator $validate = null): bool
    {
        if (!isset($this->values[$key])) {
            return $default;
        }

        $value = filter_var($this->values[$key], FILTER_VALIDATE_BOOLEAN);

        if ($validate !== null) {
            $this->validate($key, $value, $validate);
        }

        return $value;
    }

    public function string(string $key, string $default = '', ?RequestValidator $validate = null): string
    {
        if (!isset($this->values[$key])) {
            return $default;
        }

        $value = (string) $this->values[$key];

        if ($validate !== null) {
            $this->validate($key, $value, $validate);
        }

        return $value;
    }

    /** @throws \RuntimeException|ValidationException */
    public function required(string $key, ?RequestValidator $validate = null): mixed
    {
        if (!$this->has($key)) {
            throw new \RuntimeException("Missing required body parameter: {$key}");
        }

        $value = $this->values[$key];

        if ($validate !== null && $value !== null) {
            $this->validate($key, $value, $validate);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /** @throws ValidationException */
    private function validate(string $key, mixed $value, RequestValidator $validator): void
    {
        $cacheKey = $key . ':' . spl_object_id($validator);

        if (isset($this->validationCache[$cacheKey])) {
            return;
        }

        if (!$validator($value)) {
            throw new ValidationException($key, $value, $validator);
        }

        $this->validationCache[$cacheKey] = true;
    }
}
