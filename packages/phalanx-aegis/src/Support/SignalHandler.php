<?php

declare(strict_types=1);

namespace Phalanx\Support;

use OpenSwoole\Process;

final readonly class SignalHandler
{
    public static function register(callable $shutdown): void
    {
        self::registerSignal(SIGINT, $shutdown);
        self::registerSignal(SIGTERM, $shutdown);
    }

    public static function ignoreShutdownSignals(): void
    {
        self::registerSignal(SIGINT, static function (): void {
        });
        self::registerSignal(SIGTERM, static function (): void {
        });
    }

    private static function registerSignal(int $signal, callable $shutdown): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            Process::signal($signal, static function () use ($shutdown): void {
                $shutdown();
            });
        } finally {
            restore_error_handler();
        }
    }
}
