<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

final readonly class QueryParams
{
    /** @param array<string, mixed> $values */
    public function __construct(
        private array $values = [],
    ) {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->values[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function int(string $name, ?int $default = null): ?int
    {
        if (!isset($this->values[$name])) {
            return $default;
        }

        return (int) $this->values[$name];
    }

    public function bool(string $name, bool $default = false): bool
    {
        if (!isset($this->values[$name])) {
            return $default;
        }

        return filter_var($this->values[$name], FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
