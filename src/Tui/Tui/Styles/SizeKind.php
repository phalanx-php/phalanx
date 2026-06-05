<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Styles;

enum SizeKind
{
    case Fill;
    case Fixed;
    case Percent;
    case Between;
    case Fractional;
}
