<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Drawing;

enum ScreenMode
{
    case Alternate;
    case Inline;
    case Detect;
}
