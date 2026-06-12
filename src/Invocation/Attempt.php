<?php

declare(strict_types=1);

namespace Phalanx\Invocation;

use InvalidArgumentException;

final class Attempt
{
    private function __construct(
        private(set) int $number,
    ) {
    }

    public static function first(): self
    {
        return new self(1);
    }

    public static function of(int $number): self
    {
        if ($number < 1) {
            throw new InvalidArgumentException('Attempt number must be at least 1.');
        }

        return new self($number);
    }

    public function next(): self
    {
        return new self($this->number + 1);
    }

    public function isFirst(): bool
    {
        return $this->number === 1;
    }
}
