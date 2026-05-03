<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

final readonly class Stoa
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $context */
    public static function starting(array $context = []): StoaApplicationBuilder
    {
        return new StoaApplicationBuilder($context);
    }
}
