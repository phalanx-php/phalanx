<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tool;

enum Disposition
{
    case Continue;
    case Terminate;
    case Delegate;
    case Escalate;
    case Retry;
}
