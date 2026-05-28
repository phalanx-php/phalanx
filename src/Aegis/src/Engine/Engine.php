<?php

declare(strict_types=1);

namespace Phalanx\Engine;

final class Engine
{
    private static ?EngineDriver $engine = null;

    private function __construct()
    {
    }

    public static function boot(EngineDriver $engine): void
    {
        if (self::$engine !== null) {
            throw new \RuntimeException(
                'Engine already booted. Call Engine::reset() first in test tearDown if re-booting.',
            );
        }

        self::$engine = $engine;
    }

    public static function coroutine(): CoroutineDriver
    {
        return self::engine()->coroutine();
    }

    public static function channels(): ChannelFactory
    {
        return self::engine()->channels();
    }

    public static function timers(): TimerDriver
    {
        return self::engine()->timers();
    }

    public static function hooks(): RuntimeHookDriver
    {
        return self::engine()->hooks();
    }

    public static function signals(): SignalDriver
    {
        return self::engine()->signals();
    }

    public static function createWaitGroup(): WaitGroupHandle
    {
        return self::engine()->createWaitGroup();
    }

    public static function name(): string
    {
        return self::engine()->name();
    }

    public static function isBooted(): bool
    {
        return self::$engine !== null;
    }

    /** @internal */
    public static function reset(): void
    {
        self::$engine = null;
    }

    private static function engine(): EngineDriver
    {
        return self::$engine ?? throw new \RuntimeException(
            'Engine not booted. Call Engine::boot() before using runtime primitives.',
        );
    }
}
