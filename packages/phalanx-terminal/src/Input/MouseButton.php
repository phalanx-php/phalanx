<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Input;

enum MouseButton
{
    case Left;
    case Middle;
    case Right;
    case ScrollUp;
    case ScrollDown;
    case None;
}
