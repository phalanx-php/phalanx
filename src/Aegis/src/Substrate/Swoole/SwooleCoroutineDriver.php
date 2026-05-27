<?php

declare(strict_types=1);

namespace Phalanx\Substrate\Swoole;

use Phalanx\Substrate\CoroutineDriver;
use Swoole\Coroutine;

final class SwooleCoroutineDriver implements CoroutineDriver
{
    public function create(\Closure $fn): int|false
    {
        return Coroutine::create($fn);
    }

    public function exists(int $cid): bool
    {
        return Coroutine::exists($cid);
    }

    public function cancel(int $cid): bool
    {
        return Coroutine::cancel($cid);
    }

    public function isCanceled(): bool
    {
        return Coroutine::isCanceled();
    }

    public function getCid(): int
    {
        return Coroutine::getCid();
    }

    public function usleep(int $microseconds): bool
    {
        return Coroutine::usleep($microseconds);
    }

    public function run(\Closure $body): void
    {
        \Swoole\Coroutine\run($body);
    }

    public function stats(): array
    {
        return Coroutine::stats();
    }

    public function getContext(?int $cid = null): ?\ArrayObject
    {
        return $cid === null ? Coroutine::getContext() : Coroutine::getContext($cid);
    }

    public function setOptions(array $options): void
    {
        Coroutine::set($options);
    }
}
