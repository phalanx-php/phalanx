<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

final class CircuitKey
{
    private function __construct(
        private(set) string $value,
    ) {
    }

    public static function from(string $key): self
    {
        return new self($key);
    }
}
