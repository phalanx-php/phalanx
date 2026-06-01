<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Styles;

enum ColorKind
{
    case Rgb;
    case Indexed;
    case Named;
}
