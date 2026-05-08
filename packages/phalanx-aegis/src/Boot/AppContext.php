<?php

declare(strict_types=1);

namespace Phalanx\Boot;

use Phalanx\Boot\Exception\MissingContextValue;

/**
 * Typed wrapper around the application context map produced by
 * symfony/runtime and consumed by every Phalanx ServiceBundle.
 *
 * Replaces raw `array $context`. All access is explicit: callers
 * must declare what they read and how (string/int/bool), and missing
 * required keys throw {@see MissingContextValue} instead of silently
 * resolving to null.
 *
 * Immutable: every mutation (`with()`) returns a new instance.
 */
final readonly class AppContext
{
    /** @param array<string,mixed> $values */
    public function __construct(public array $values = [])
    {
    }

    /**
     * Build from the raw context array Symfony Runtime hands the entry closure.
     *
     * @param array<string,mixed> $context
     */
    public static function fromSymfonyRuntime(array $context): self
    {
        return new self($context);
    }

    /**
     * Test/demo helper for building a context inline without going through Symfony.
     *
     * @param array<string,mixed> $values
     */
    public static function test(array $values = []): self
    {
        return new self($values);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function require(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw MissingContextValue::forKey($key);
        }
        return $this->values[$key];
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? $default;
        if ($value === null) {
            throw MissingContextValue::forKey($key);
        }
        if (!is_string($value) && !is_numeric($value) && !is_bool($value)) {
            throw MissingContextValue::wrongType($key, 'string', get_debug_type($value));
        }
        return (string) $value;
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->values[$key] ?? $default;
        if ($value === null) {
            throw MissingContextValue::forKey($key);
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit(ltrim($value, '-'))) {
            return (int) $value;
        }
        throw MissingContextValue::wrongType($key, 'int', get_debug_type($value));
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        if (!array_key_exists($key, $this->values)) {
            if ($default === null) {
                throw MissingContextValue::forKey($key);
            }
            return $default;
        }
        $value = $this->values[$key];
        return match (true) {
            is_bool($value) => $value,
            $value === '1', $value === 'true', $value === 'on', $value === 'yes' => true,
            $value === '0', $value === 'false', $value === 'off', $value === 'no', $value === '' => false,
            default => throw MissingContextValue::wrongType($key, 'bool', get_debug_type($value)),
        };
    }

    public function with(string $key, mixed $value): self
    {
        return new self([...$this->values, $key => $value]);
    }
}
