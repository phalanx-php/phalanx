<?php

declare(strict_types=1);

namespace Phalanx\Invocation;

use InvalidArgumentException;

final class InvocationId
{
    private function __construct(
        private(set) string $value,
    ) {
    }

    public static function of(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Invocation id cannot be empty.');
        }

        return new self($value);
    }

    public function eq(self $other): bool
    {
        return $this->value === $other->value;
    }
}
