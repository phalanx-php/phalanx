<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Drawing\Rect;
use Phalanx\Tui\Navigation\OverlayFrame;

interface HasOverlayFrame
{
    public function overlayFrame(Rect $bounds): OverlayFrame;
}
