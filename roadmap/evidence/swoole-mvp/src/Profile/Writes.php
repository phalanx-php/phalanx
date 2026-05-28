<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Profile;

interface Writes
{
    /** @return array<class-string, list<string>> */
    public static function writes(): array;
}
