<?php

declare(strict_types=1);

namespace AegisSwoole\Tests;

final readonly class Result
{
    private function __construct(
        public bool $ok,
        public string $reason,
    ) {
    }

    public static function pass(): self
    {
        return new self(true, '');
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
