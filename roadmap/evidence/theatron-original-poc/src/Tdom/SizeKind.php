<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom;

enum SizeKind
{
    case Fill;
    case Fixed;
    case Percent;
    case Between;
    case Fractional;
}
