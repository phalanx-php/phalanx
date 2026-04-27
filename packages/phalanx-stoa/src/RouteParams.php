<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

final readonly class RouteParams
{
    /** @param array<string, string> $values */
    public function __construct(
        private array $values = [],
    ) {
    }

    public function get(string $name, ?string $default = null): ?string
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

    /** @throws \RuntimeException */
    public function required(string $name): string
    {
        if (!$this->has($name)) {
            throw new \RuntimeException("Missing required route parameter: $name");
        }

        return $this->values[$name];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->values;
    }
}
