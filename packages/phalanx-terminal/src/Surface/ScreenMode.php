<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Surface;

enum ScreenMode
{
    case Alternate;
    case Inline;
    case Detect;
}
