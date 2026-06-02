<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Inputs;

enum MouseAction
{
    case Press;
    case Release;
    case Move;
}
