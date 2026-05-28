<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Profile;

interface Computes
{
    public static function estimatedMs(): int;

    /** @return list<class-string> */
    public static function using(): array;

    /** @return class-string */
    public static function accepts(): string;

    /** @return class-string */
    public static function returns(): string;
}
