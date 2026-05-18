<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

enum Disposition: string
{
    case Continue  = 'continue';
    case Terminate = 'terminate';
    case Suspend   = 'suspend';
}
