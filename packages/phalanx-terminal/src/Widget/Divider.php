<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;

final class Divider implements Widget
{
    private Style $style;

    public function __construct(
        private DividerDirection $direction = DividerDirection::Horizontal,
        ?Style $style = null,
        private ?string $char = null,
    ) {
        $this->style = $style ?? Style::new();
    }

    public static function horizontal(?Style $style = null): self
    {
        return new self(DividerDirection::Horizontal, $style);
    }

    public static function vertical(?Style $style = null): self
    {
        return new self(DividerDirection::Vertical, $style);
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($this->direction === DividerDirection::Horizontal) {
            $char = $this->char ?? '─';
            $y = $area->y;

            for ($x = $area->x; $x < $area->right; $x++) {
                $buffer->set($x, $y, $char, $this->style);
            }

            return;
        }

        $char = $this->char ?? '│';
        $x = $area->x;

        for ($y = $area->y; $y < $area->bottom; $y++) {
            $buffer->set($x, $y, $char, $this->style);
        }
    }
}
