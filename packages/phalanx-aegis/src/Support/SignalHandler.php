<?php

declare(strict_types=1);

namespace Phalanx\Support;

final readonly class SignalHandler
{
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function register(callable $shutdown): void
    {
        if (self::isWindows() || !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () use ($shutdown): void {
            $shutdown();
        });
        pcntl_signal(SIGTERM, static function () use ($shutdown): void {
            $shutdown();
        });
    }
}
