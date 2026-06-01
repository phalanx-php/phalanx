<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Drawing\Rect;
use Phalanx\Theatron\Tui\Navigation\OverlayFrame;

interface HasOverlayFrame
{
    public function overlayFrame(Rect $bounds): OverlayFrame;
}
