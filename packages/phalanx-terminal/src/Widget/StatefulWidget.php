<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;

interface StatefulWidget
{
    public function render(Rect $area, Buffer $buffer, object $state): void;
}
