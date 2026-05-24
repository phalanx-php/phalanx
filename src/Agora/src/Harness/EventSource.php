<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

enum EventSource: string
{
    case Agora = 'agora';
    case Athena = 'athena';
    case Panoply = 'panoply';
    case Runtime = 'runtime';
}
