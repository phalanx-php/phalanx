<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Style;

enum ColorKind
{
    case Rgb;
    case Indexed;
    case Named;
}
