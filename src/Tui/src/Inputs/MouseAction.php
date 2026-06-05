<?php

declare(strict_types=1);

namespace Phalanx\Tui\Inputs;

enum MouseAction
{
    case Press;
    case Release;
    case Move;
}
