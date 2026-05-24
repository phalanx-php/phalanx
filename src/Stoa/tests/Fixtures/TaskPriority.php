<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Fixtures;

enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';
}
