<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stage;

enum ScreenMode
{
    case Alternate;
    case Inline;
    case Detect;
}
