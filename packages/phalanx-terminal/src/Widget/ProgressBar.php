<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;

final class ProgressBar implements Widget
{
    private float $progress = 0.0;
    private string $label = '';

    private Style $filledStyle;
    private Style $emptyStyle;
    private Style $labelStyle;

    public function __construct(
        ?Style $filledStyle = null,
        ?Style $emptyStyle = null,
        ?Style $labelStyle = null,
    ) {
        $this->filledStyle = $filledStyle ?? Style::new()->fg('green');
        $this->emptyStyle = $emptyStyle ?? Style::new()->dim();
        $this->labelStyle = $labelStyle ?? Style::new();
    }

    public function setProgress(float $progress): void
    {
        $this->progress = max(0.0, min(1.0, $progress));
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public float $value {
        get => $this->progress;
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height === 0 || $area->width === 0) {
            return;
        }

        $pctText = sprintf(' %3d%%', (int) round($this->progress * 100));
        $labelLen = $this->label !== '' ? mb_strlen($this->label) + 1 : 0;
        $pctLen = 5;
        $barWidth = $area->width - $pctLen - $labelLen;

        if ($barWidth < 3) {
            $buffer->putString($area->x, $area->y, $pctText, $this->labelStyle);
            return;
        }

        $x = $area->x;

        if ($this->label !== '') {
            $x = $buffer->putString($x, $area->y, $this->label . ' ', $this->labelStyle);
        }

        $filled = (int) round($barWidth * $this->progress);
        $empty = $barWidth - $filled;

        for ($i = 0; $i < $filled; $i++) {
            $buffer->set($x + $i, $area->y, '█', $this->filledStyle);
        }

        for ($i = 0; $i < $empty; $i++) {
            $buffer->set($x + $filled + $i, $area->y, '░', $this->emptyStyle);
        }

        $buffer->putString($x + $barWidth, $area->y, $pctText, $this->labelStyle);
    }
}
