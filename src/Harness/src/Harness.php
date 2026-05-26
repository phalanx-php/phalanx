<?php

declare(strict_types=1);

namespace Phalanx\Harness;

final class Harness
{
    /** @param array<string, mixed> $context */
    public static function app(array $context = []): HarnessBuilder
    {
        return new HarnessBuilder($context);
    }
}
