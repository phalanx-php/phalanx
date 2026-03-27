<?php

declare(strict_types=1);

namespace Phalanx\Console;

final class CommandArgs
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

    /** @throws InvalidInputException */
    public function required(string $name): mixed
    {
        if (!$this->has($name)) {
            throw new InvalidInputException("Missing required argument: $name");
        }

        return $this->values[$name];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
