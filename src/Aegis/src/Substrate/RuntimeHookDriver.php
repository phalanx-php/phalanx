<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

interface RuntimeHookDriver
{
    public function enableCoroutine(int $flags): void;

    public function getHookFlags(): int;

    /** @return array<int, string> */
    public function hookFlagNames(): array;

    public function hookFlags(): RuntimeHookFlags;
}
