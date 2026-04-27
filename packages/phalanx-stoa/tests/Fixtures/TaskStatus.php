<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
}
