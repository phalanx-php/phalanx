<?php

declare(strict_types=1);

namespace Convoy\Support;

use React\EventLoop\Loop;

final class SignalHandler
{
    private static bool $registered = false;

    /**
     * Register shutdown handler for both Unix and Windows platforms.
     *
     * Unix: Uses Loop::addSignal() for SIGINT/SIGTERM
     * Windows: Uses sapi_windows_set_ctrl_handler() for CTRL+C/CTRL+BREAK
     */
    public static function register(callable $handler): void
    {
        if (self::$registered) {
            return;
        }

        if (self::isWindows()) {
            self::registerWindows($handler);
        } else {
            self::registerUnix($handler);
        }

        self::$registered = true;
    }

    public static function reset(): void
    {
        self::$registered = false;
    }

    public static function isWindows(): bool
    {
        return \PHP_OS_FAMILY === 'Windows';
    }

    private static function registerUnix(callable $handler): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        if (defined('SIGINT')) {
            Loop::addSignal(\SIGINT, $handler);
        }
        if (defined('SIGTERM')) {
            Loop::addSignal(\SIGTERM, $handler);
        }
    }

    private static function registerWindows(callable $handler): void
    {
        if (!function_exists('sapi_windows_set_ctrl_handler')) {
            return;
        }

        sapi_windows_set_ctrl_handler(static function (int $event) use ($handler): void {
            if ($event === \PHP_WINDOWS_EVENT_CTRL_C || $event === \PHP_WINDOWS_EVENT_CTRL_BREAK) {
                $handler();
            }
        });
    }
}
