<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;

interface Widget
{
    public function render(Rect $area, Buffer $buffer): void;
}
