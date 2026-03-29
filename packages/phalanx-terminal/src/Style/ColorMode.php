<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Style;

enum ColorMode
{
    case Ansi4;
    case Ansi8;
    case Ansi24;
}
