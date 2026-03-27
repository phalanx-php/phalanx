<?php

declare(strict_types=1);

namespace Phalanx\Task;

enum Pool
{
    case Http;
    case Database;
    case Redis;
    case FileSystem;
    case Queue;

    public function key(): string
    {
        return self::class . '::' . $this->name;
    }
}
