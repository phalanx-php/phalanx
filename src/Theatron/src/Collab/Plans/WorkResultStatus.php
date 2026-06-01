<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Plans;

enum WorkResultStatus: string
{
    case Done = 'done';
    case Blocked = 'blocked';
    case Failed = 'failed';
}
