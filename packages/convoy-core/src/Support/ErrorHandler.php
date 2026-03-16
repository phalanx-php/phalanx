<?php

declare(strict_types=1);

namespace Convoy\Support;

final class ErrorHandler
{
    /** @var callable(string): void */
    private static $handler = null;

    public static function report(string $message): void
    {
        if (self::$handler !== null) {
            (self::$handler)($message);
            return;
        }

        error_log($message);
    }

    /** @param callable(string): void $handler */
    public static function use(callable $handler): void
    {
        self::$handler = $handler;
    }

    public static function reset(): void
    {
        self::$handler = null;
    }
}
