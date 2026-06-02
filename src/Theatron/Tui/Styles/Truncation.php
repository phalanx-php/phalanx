<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Styles;

enum Truncation
{
    case Ellipsis;
    case Head;
    case Tail;
    case None;
}
