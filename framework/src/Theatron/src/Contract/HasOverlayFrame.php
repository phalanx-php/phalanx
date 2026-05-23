<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Overlay\OverlayFrame;

interface HasOverlayFrame
{
    public function overlayFrame(Rect $bounds): OverlayFrame;
}
