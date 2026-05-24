<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

/**
 * @template T
 */
interface RequestCtxKey
{
    public function key(): string;
}
