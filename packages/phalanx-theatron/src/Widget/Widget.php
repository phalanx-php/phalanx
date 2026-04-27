<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Widget;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;

interface Widget
{
    public function render(Rect $area, Buffer $buffer): void;
}
