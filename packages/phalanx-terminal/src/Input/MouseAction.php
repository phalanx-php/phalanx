<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Input;

enum MouseAction
{
    case Press;
    case Release;
    case Move;
}
