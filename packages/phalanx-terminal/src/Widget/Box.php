<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;
use Phalanx\Terminal\Widget\Text\Span;

final class Box implements Widget
{
    public function __construct(
        private Widget $inner,
        private BoxStyle $border = BoxStyle::Single,
        private ?string $title = null,
        private ?Style $borderStyle = null,
        private ?Style $titleStyle = null,
    ) {}

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->width < 2 || $area->height < 2) {
            return;
        }

        $style = $this->borderStyle ?? Style::new();
        [$tl, $tr, $bl, $br, $h, $v] = $this->border->chars();

        $buffer->set($area->x, $area->y, $tl, $style);
        $buffer->set($area->right - 1, $area->y, $tr, $style);
        $buffer->set($area->x, $area->bottom - 1, $bl, $style);
        $buffer->set($area->right - 1, $area->bottom - 1, $br, $style);

        for ($x = $area->x + 1; $x < $area->right - 1; $x++) {
            $buffer->set($x, $area->y, $h, $style);
            $buffer->set($x, $area->bottom - 1, $h, $style);
        }

        for ($y = $area->y + 1; $y < $area->bottom - 1; $y++) {
            $buffer->set($area->x, $y, $v, $style);
            $buffer->set($area->right - 1, $y, $v, $style);
        }

        if ($this->title !== null && $area->width > 4) {
            $maxTitleLen = $area->width - 4;
            $titleText = mb_strlen($this->title) > $maxTitleLen
                ? mb_substr($this->title, 0, $maxTitleLen - 1) . '~'
                : $this->title;

            $titleStr = " {$titleText} ";
            $buffer->putString(
                $area->x + 1,
                $area->y,
                $titleStr,
                $this->titleStyle ?? $style,
            );
        }

        $innerArea = Rect::of(
            $area->x + 1,
            $area->y + 1,
            $area->width - 2,
            $area->height - 2,
        );

        if ($innerArea->width > 0 && $innerArea->height > 0) {
            $this->inner->render($innerArea, $buffer);
        }
    }
}
