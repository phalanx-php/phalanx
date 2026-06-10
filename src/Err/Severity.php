<?php

declare(strict_types=1);

namespace Phalanx\Err;

enum Severity
{
    case Expected;
    case Transient;
    case Degraded;
    case Fatal;
}
