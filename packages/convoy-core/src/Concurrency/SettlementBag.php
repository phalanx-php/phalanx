<?php

declare(strict_types=1);

namespace Convoy\Concurrency;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Collection wrapper for settle() results with ergonomic value extraction.
 *
 * @implements ArrayAccess<string|int, Settlement>
 * @implements IteratorAggregate<string|int, Settlement>
 */
final class SettlementBag implements ArrayAccess, IteratorAggregate, Countable
{
    /** @var array<string|int, mixed> */
    private(set) array $cachedValues;

    /** @var array<string|int, \Throwable> */
    private(set) array $cachedErrors;

    /** @var array<string|int, mixed> */
    public array $values {
        get => $this->cachedValues;
    }

    /** @var array<string|int, \Throwable> */
    public array $errors {
        get => $this->cachedErrors;
    }

    /**
     * True if all settlements succeeded.
     */
    public bool $allOk {
        get => $this->cachedErrors === [];
    }

    /**
     * True if at least one settlement succeeded.
     */
    public bool $anyOk {
        get => $this->cachedValues !== [];
    }

    /**
     * True if all settlements failed.
     */
    public bool $allErr {
        get => $this->cachedValues === [];
    }

    /**
     * True if at least one settlement failed.
     */
    public bool $anyErr {
        get => $this->cachedErrors !== [];
    }

    /** @var list<string|int> */
    public array $okKeys {
        get => array_keys($this->cachedValues);
    }

    /** @var list<string|int> */
    public array $errKeys {
        get => array_keys($this->cachedErrors);
    }

    /**
     * @param array<string|int, Settlement> $settlements
     */
    public function __construct(
        private(set) readonly array $settlements,
    ) {
        $values = [];
        $errors = [];
        foreach ($settlements as $key => $settlement) {
            if ($settlement->isOk) {
                $values[$key] = $settlement->value;
            } elseif ($settlement->error !== null) {
                $errors[$key] = $settlement->error;
            }
        }

        $this->cachedValues = $values;
        $this->cachedErrors = $errors;
    }

    /**
     * Get value for key, returning default if failed or missing.
     */
    public function get(string|int $key, mixed $default = null): mixed
    {
        if (!isset($this->settlements[$key])) {
            return $default;
        }

        return $this->settlements[$key]->unwrapOr($default);
    }

    /**
     * Bulk extract values with defaults.
     *
     * @param array<string|int, mixed> $defaults Key => default value pairs
     * @return array<string|int, mixed>
     */
    public function extract(array $defaults): array
    {
        $result = [];

        foreach ($defaults as $key => $default) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * Unwrap all successes, throwing if any failed.
     *
     * @return array<string|int, mixed>
     * @throws \Throwable First error encountered
     */
    public function unwrapAll(): array
    {
        if ($this->anyErr) {
            $firstKey = array_key_first($this->cachedErrors);
            throw $this->cachedErrors[$firstKey];
        }

        return $this->cachedValues;
    }

    /**
     * Partition into [successes, failures].
     *
     * @return array{0: array<string|int, mixed>, 1: array<string|int, \Throwable>}
     */
    public function partition(): array
    {
        return [$this->cachedValues, $this->cachedErrors];
    }

    /**
     * Map over successful values only.
     *
     * @param callable(mixed, string|int): mixed $fn
     * @return array<string|int, mixed>
     */
    public function mapOk(callable $fn): array
    {
        $result = [];

        foreach ($this->cachedValues as $key => $value) {
            $result[$key] = $fn($value, $key);
        }

        return $result;
    }

    /**
     * Get raw Settlement for a key.
     */
    public function settlement(string|int $key): ?Settlement
    {
        return $this->settlements[$key] ?? null;
    }

    /**
     * Check if a specific key succeeded.
     */
    public function isOk(string|int $key): bool
    {
        return isset($this->settlements[$key]) && $this->settlements[$key]->isOk;
    }

    /**
     * Check if a specific key failed.
     */
    public function isErr(string|int $key): bool
    {
        return isset($this->settlements[$key]) && !$this->settlements[$key]->isOk;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->settlements[$offset]);
    }

    public function offsetGet(mixed $offset): ?Settlement
    {
        return $this->settlements[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('SettlementBag is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('SettlementBag is immutable');
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->settlements);
    }

    public function count(): int
    {
        return count($this->settlements);
    }
}
