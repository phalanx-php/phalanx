<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;
use Phalanx\Terminal\Widget\Text\Line;
use Phalanx\Terminal\Widget\Text\Span;

final class StatusBar implements Widget
{
    /** @var list<Span> */
    private array $left = [];

    /** @var list<Span> */
    private array $right = [];

    private Style $barStyle;

    public function __construct(?Style $barStyle = null)
    {
        $this->barStyle = $barStyle ?? Style::new();
    }

    public function setLeft(Span ...$spans): void
    {
        $this->left = array_values($spans);
    }

    public function setRight(Span ...$spans): void
    {
        $this->right = array_values($spans);
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height === 0 || $area->width === 0) {
            return;
        }

        $buffer->fill($area, $this->barStyle);

        if ($this->left !== []) {
            $leftLine = Line::from(...$this->left);
            $buffer->putLine($area->x, $area->y, $leftLine, $area->width);
        }

        if ($this->right !== []) {
            $rightLine = Line::from(...$this->right);
            $rightWidth = $rightLine->width;
            $startX = $area->x + $area->width - $rightWidth;

            if ($startX > $area->x) {
                $buffer->putLine($startX, $area->y, $rightLine, $rightWidth);
            }
        }
    }
}
