<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Drawing\Rect;
use Phalanx\Tui\Tui\Navigation\OverlayFrame;

interface HasOverlayFrame
{
    public function overlayFrame(Rect $bounds): OverlayFrame;
}
