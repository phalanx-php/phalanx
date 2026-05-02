<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use RuntimeException;
use Throwable;
use Traversable;

/**
 * Keyed collection of Settlements. Returned by settle().
 *
 * @implements ArrayAccess<string|int, Settlement>
 * @implements IteratorAggregate<string|int, Settlement>
 */
final class SettlementBag implements ArrayAccess, IteratorAggregate, Countable
{
    /** @var array<string|int, mixed> */
    public array $values {
        get {
            $out = [];
    foreach ($this->settlements as $k => $s) {
        if ($s->isOk) {
            $out[$k] = $s->value;
        }
    }
            return $out;
        }
    }

    /** @var array<string|int, Throwable> */
    public array $errors {
        get {
            $out = [];
    foreach ($this->settlements as $k => $s) {
        if (!$s->isOk && $s->error !== null) {
            $out[$k] = $s->error;
        }
    }

            return $out;
        }
    }

    public bool $allOk {
        get {
    foreach ($this->settlements as $s) {
        if (!$s->isOk) {
            return false;
        }
    }
            return true;
        }
    }

    public bool $anyOk {
        get {
    foreach ($this->settlements as $s) {
        if ($s->isOk) {
            return true;
        }
    }
            return false;
        }
    }

    public bool $allErr {
        get => !$this->anyOk;
    }

    public bool $anyErr {
        get => !$this->allOk;
    }

    /** @var list<string|int> */
    public array $okKeys {
        get => array_keys($this->values);
    }

    /** @var list<string|int> */
    public array $errKeys {
        get => array_keys($this->errors);
    }

    /** @param array<string|int, Settlement> $settlements */
    public function __construct(private readonly array $settlements)
    {
    }

    public function get(string|int $key, mixed $default = null): mixed
    {
        $s = $this->settlements[$key] ?? null;
        return $s !== null && $s->isOk ? $s->value : $default;
    }

    public function settlement(string|int $key): ?Settlement
    {
        return $this->settlements[$key] ?? null;
    }

    public function isOk(string|int $key): bool
    {
        $settlement = $this->settlements[$key] ?? null;

        return $settlement !== null && $settlement->isOk;
    }

    public function isErr(string|int $key): bool
    {
        $s = $this->settlements[$key] ?? null;
        return $s !== null && !$s->isOk;
    }

    /** @return array<string|int, mixed> */
    public function unwrapAll(): array
    {
        if (!$this->allOk) {
            $first = $this->errors[array_key_first($this->errors)];
            throw new RuntimeException('SettlementBag::unwrapAll: at least one task failed', 0, $first);
        }
        return $this->values;
    }

    /** @return array{0: array<string|int, mixed>, 1: array<string|int, Throwable>} */
    public function partition(): array
    {
        return [$this->values, $this->errors];
    }

    /**
     * @template T
     * @param callable(mixed, string|int): T $fn
     * @return array<string|int, T>
     */
    public function mapOk(callable $fn): array
    {
        $out = [];
        foreach ($this->settlements as $k => $s) {
            if ($s->isOk) {
                $out[$k] = $fn($s->value, $k);
            }
        }
        return $out;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->settlements[$offset]);
    }

    public function offsetGet(mixed $offset): Settlement
    {
        return $this->settlements[$offset] ?? throw new RuntimeException("No settlement for key {$offset}");
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new RuntimeException('SettlementBag is immutable');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new RuntimeException('SettlementBag is immutable');
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
