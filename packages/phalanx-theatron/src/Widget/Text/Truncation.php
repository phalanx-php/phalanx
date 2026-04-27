<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Widget\Text;

enum Truncation
{
    case Ellipsis;
    case Head;
    case Tail;
    case None;
}
