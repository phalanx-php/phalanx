<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

use Phalanx\Console\Style\Theme;

/**
 * Pure string renderer — no state, no I/O. Caller owns current value and total.
 *
 * Percentage is always 5 characters (' %3d%%') so the bar doesn't shift
 * horizontally as the number grows — naive '%d%%' formatting is a common
 * visual glitch.
 */
final class ProgressBar
{
    public function __construct(
        private readonly Theme $theme,
        private readonly string $filledChar = '█',
        private readonly string $emptyChar = '░',
    ) {}

    public function render(int $value, int $total, int $width, string $label = ''): string
    {
        $pctText  = sprintf(' %3d%%', (int) round($value / max(1, $total) * 100));
        $labelLen = $label !== '' ? mb_strlen($label) + 1 : 0;
        $barWidth = $width - 5 - $labelLen;

        if ($barWidth < 3) {
            $prefix = $label !== '' ? $this->theme->muted->apply($label) . ' ' : '';
            return $prefix . $pctText;
        }

        $filled = (int) round($barWidth * $value / max(1, $total));
        $empty  = $barWidth - $filled;

        $bar = $this->theme->accent->apply(str_repeat($this->filledChar, $filled))
             . $this->theme->muted->apply(str_repeat($this->emptyChar, $empty));

        $prefix = $label !== '' ? $this->theme->muted->apply($label) . ' ' : '';

        return $prefix . $bar . $pctText;
    }
}
