<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

interface CoroutineDriver
{
    public function create(\Closure $fn): int|false;

    public function exists(int $cid): bool;

    public function cancel(int $cid): bool;

    public function isCanceled(): bool;

    public function getCid(): int;

    public function usleep(int $microseconds): bool;

    public function run(\Closure $body): void;

    /** @return array<string, int|float|string> */
    public function stats(): array;

    public function getContext(?int $cid = null): ?\ArrayObject;

    /** @param array<string, mixed> $options */
    public function setOptions(array $options): void;
}
