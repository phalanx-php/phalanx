<?php

declare(strict_types=1);

namespace Phalanx\Boot\Exception;

use RuntimeException;

final class MissingContextValue extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'Missing required context value "%s". Set it in your .env file or pass it through symfony/runtime context.',
            $key,
        ));
    }

    public static function wrongType(string $key, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Context value "%s" expected %s, got %s.',
            $key,
            $expected,
            $actual,
        ));
    }
}
