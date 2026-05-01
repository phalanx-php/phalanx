<?php

declare(strict_types=1);

namespace Phalanx\Support;

use Closure;
use InvalidArgumentException;
use Phalanx\Disposable;
use React\EventLoop\Loop;
use ReflectionFunction;

final class ScopedSignalHandler
{
    /**
     * Bind one or more signals to a handler for the lifetime of the given scope.
     *
     * Fiber safety contract — the handler is guaranteed to run in a fresh
     * loop tick, never inside the tick that delivered the signal. This
     * matters because the signal-delivering tick may itself be nested
     * inside a Loop::run() that is suspended under a fiber's await();
     * resolving a Deferred or disposing a scope from within that nested
     * context produces the FiberError this primitive exists to prevent.
     * Internally the user handler is wrapped in Loop::futureTick() so
     * callers do not have to remember the deferral discipline.
     *
     * Never call pcntl_async_signals(true) alongside this; that pattern
     * fires mid-fiber and corrupts execution context.
     *
     * Windows path uses sapi_windows_set_ctrl_handler. Only SIGINT
     * (CTRL_C) and SIGTERM (CTRL_BREAK) are supported on Windows; other
     * signals in the input list are silently ignored on that platform.
     *
     * @param int|list<int> $signals
     */
    public static function on(Disposable $scope, int|array $signals, Closure $handler): void
    {
        if (new ReflectionFunction($handler)->getClosureThis() !== null) {
            throw new InvalidArgumentException(
                'ScopedSignalHandler handler must be a static closure to prevent reference cycles. ' .
                'Use: static fn() => ... or pass $this via use()'
            );
        }

        $normalized = self::normalize($signals);

        if ($normalized === []) {
            return;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            self::registerWindows($scope, $normalized, $handler);

            return;
        }

        self::registerUnix($scope, $normalized, $handler);
    }

    /**
     * @param int|list<int> $signals
     * @return list<int>
     */
    private static function normalize(int|array $signals): array
    {
        if (is_int($signals)) {
            return [$signals];
        }

        return array_values(array_unique($signals));
    }

    /**
     * @param list<int> $signals
     */
    private static function registerUnix(Disposable $scope, array $signals, Closure $handler): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $loop = Loop::get();
        $disposed = false;
        $listener = self::makeFiberSafeListener($loop, $handler, $disposed);

        foreach ($signals as $signal) {
            $loop->addSignal($signal, $listener);
        }

        $scope->onDispose(static function () use ($loop, $signals, $listener, &$disposed): void {
            $disposed = true;
            foreach ($signals as $signal) {
                $loop->removeSignal($signal, $listener);
            }
        });
    }

    /**
     * @param list<int> $signals
     */
    private static function registerWindows(Disposable $scope, array $signals, Closure $handler): void
    {
        if (!function_exists('sapi_windows_set_ctrl_handler')) {
            return;
        }

        $events = self::windowsEventsFor($signals);

        if ($events === []) {
            return;
        }

        $loop = Loop::get();
        $disposed = false;
        $deferred = self::makeFiberSafeListener($loop, $handler, $disposed);

        $ctrlHandler = static function (int $event) use ($events, $deferred): void {
            if (in_array($event, $events, true)) {
                $deferred();
            }
        };

        sapi_windows_set_ctrl_handler($ctrlHandler);

        $scope->onDispose(static function () use ($ctrlHandler, &$disposed): void {
            $disposed = true;
            sapi_windows_set_ctrl_handler($ctrlHandler, false);
        });
    }

    private static function makeFiberSafeListener(
        \React\EventLoop\LoopInterface $loop,
        Closure $handler,
        bool &$disposed,
    ): Closure {
        return static function () use ($loop, $handler, &$disposed): void {
            $loop->futureTick(static function () use ($handler, &$disposed): void {
                if ($disposed) {
                    return;
                }
                $handler();
            });
        };
    }

    /**
     * @param list<int> $signals
     * @return list<int>
     */
    private static function windowsEventsFor(array $signals): array
    {
        $events = [];

        if (defined('SIGINT') && in_array(\SIGINT, $signals, true) && defined('PHP_WINDOWS_EVENT_CTRL_C')) {
            $events[] = \PHP_WINDOWS_EVENT_CTRL_C;
        }

        if (defined('SIGTERM') && in_array(\SIGTERM, $signals, true) && defined('PHP_WINDOWS_EVENT_CTRL_BREAK')) {
            $events[] = \PHP_WINDOWS_EVENT_CTRL_BREAK;
        }

        return $events;
    }
}
