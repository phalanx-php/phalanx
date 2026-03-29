<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget\Text;

enum Truncation
{
    case Ellipsis;
    case Head;
    case Tail;
    case None;
}
