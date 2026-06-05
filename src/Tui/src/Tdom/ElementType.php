<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom;

enum ElementType
{
    case Row;
    case Grid;
    case Text;
    case Panel;
    case Input;
    case Column;
    case Scroll;
    case Spinner;
    case Divider;
    case Progress;
    case StatusLine;
}
