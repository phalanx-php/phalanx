<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;

final class Sparkline implements Widget
{
    private const array BLOCKS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
    private Style $style;

    /** @param list<float> $data */
    public function __construct(
        private array $data = [],
        ?Style $style = null,
    ) {
        $this->style = $style ?? Style::new()->fg('green');
    }

    /** @param list<float> $data */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function push(float $value): void
    {
        $this->data[] = $value;
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height === 0 || $area->width === 0 || $this->data === []) {
            return;
        }

        $visibleData = array_slice($this->data, -$area->width);
        $min = min($visibleData);
        $max = max($visibleData);
        $range = $max - $min;

        foreach ($visibleData as $i => $value) {
            $x = $area->x + $i;

            if ($x >= $area->right) {
                break;
            }

            $normalized = $range > 0 ? ($value - $min) / $range : 0.5;
            $blockIdx = (int) round($normalized * (count(self::BLOCKS) - 1));
            $char = self::BLOCKS[$blockIdx];

            $buffer->set($x, $area->y, $char, $this->style);
        }
    }
}
