<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Profile;

interface Reads
{
    /** @return list<class-string> */
    public static function reads(): array;
}
