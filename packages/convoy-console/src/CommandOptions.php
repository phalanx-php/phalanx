<?php

declare(strict_types=1);

namespace Convoy\Console;

final class CommandOptions
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

    public function flag(string $name): bool
    {
        return (bool) ($this->values[$name] ?? false);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
