<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

final class Substrate
{
    private static ?SubstrateEngine $engine = null;

    public static function boot(SubstrateEngine $engine): void
    {
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

    public static function waitGroup(): WaitGroupHandle
    {
        return self::engine()->waitGroup();
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

    private static function engine(): SubstrateEngine
    {
        return self::$engine ?? throw new \RuntimeException(
            'Substrate not booted. Call Substrate::boot() before using runtime primitives.',
        );
    }
}
