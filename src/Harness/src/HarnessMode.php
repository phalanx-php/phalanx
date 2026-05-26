<?php

declare(strict_types=1);

namespace Phalanx\Harness;

enum HarnessMode: string
{
    case Ephemeral = 'ephemeral';
    case Durable = 'durable';
}
