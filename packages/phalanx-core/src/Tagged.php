<?php

declare(strict_types=1);

namespace Phalanx;

interface Tagged
{
    /** @var list<string> */
    public array $tags { get; }
}
