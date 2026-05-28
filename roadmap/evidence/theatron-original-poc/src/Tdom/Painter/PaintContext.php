<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;

class PaintContext
{
    public function __construct(
        private(set) Rect $area,
        private(set) Buffer $buffer,
    ) {
    }

    public function sub(Rect $area): self
    {
        return new self($area, $this->buffer);
    }
}
