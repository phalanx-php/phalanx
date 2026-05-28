<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

enum StoreStrategy
{
    case Concurrent;
    case Parallel;
    case Memory;
}
