<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Swoole;

use ArrayObject;
use Closure;
use Swoole\Coroutine;
use Swoole\Process;
use Swoole\Runtime;
use Swoole\Timer;

final class SwooleRuntime
{
    private function __construct()
    {
    }

    public static function create(Closure $fn): int|false
    {
        return Coroutine::create($fn);
    }

    public static function exists(int $cid): bool
    {
        return Coroutine::exists($cid);
    }

    public static function cancel(int $cid): bool
    {
        return Coroutine::cancel($cid);
    }

    public static function isCanceled(): bool
    {
        return Coroutine::isCanceled();
    }

    public static function getCid(): int
    {
        return Coroutine::getCid();
    }

    public static function usleep(int $microseconds): bool
    {
        return Coroutine::usleep($microseconds);
    }

    public static function run(Closure $body): void
    {
        Coroutine::run($body);
    }

    /**
     * @return array<string, int|float|string>
     */
    public static function stats(): array
    {
        return Coroutine::stats();
    }

    /** @return ?ArrayObject<array-key, mixed> */
    public static function getContext(?int $cid = null): ?ArrayObject
    {
        return $cid === null ? Coroutine::getContext() : Coroutine::getContext($cid);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function setOptions(array $options): void
    {
        Coroutine::set($options);
    }

    /** @return iterable<int, int> */
    public static function list(): iterable
    {
        return Coroutine::list();
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    public static function getBackTrace(int $cid, int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT, int $limit = 0): array|false
    {
        return Coroutine::getBackTrace($cid, $options, $limit);
    }

    public static function getHookFlags(): int
    {
        return Runtime::getHookFlags();
    }

    public static function enableCoroutine(int $flags): void
    {
        Runtime::enableCoroutine(flags: $flags);
    }

    public static function after(int $ms, Closure $callback): int|false
    {
        $timerId = Timer::after($ms, $callback);
        return is_int($timerId) ? $timerId : false;
    }

    public static function tick(int $ms, Closure $callback): int|false
    {
        $timerId = Timer::tick($ms, $callback);
        return is_int($timerId) ? $timerId : false;
    }

    public static function clearTimer(int $timerId): void
    {
        /** @phpstan-ignore-next-line Timer::clear() accepts the timer id in ext-swoole. */
        Timer::clear($timerId);
    }

    public static function signal(int $signal, ?Closure $handler): void
    {
        Process::signal($signal, $handler);
    }

    public static function channel(int $capacity = 0): SwooleChannel
    {
        return new SwooleChannel($capacity);
    }

    public static function waitGroup(): SwooleWaitGroup
    {
        return new SwooleWaitGroup();
    }
}
