<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

use Phalanx\Console\Style\Theme;

/**
 * Horizontal separator line with optional centered label.
 *
 *   ──────────────────────────
 *   ─────── Section ──────────
 */
final class Divider
{
    public static function render(int $width, Theme $theme, string $label = ''): string
    {
        if ($label === '') {
            return $theme->border->apply(str_repeat('─', $width));
        }

        $labelLen   = mb_strlen($label);
        $totalDash  = max(0, $width - $labelLen - 2); // 2 spaces around label
        $leftDash   = (int) floor($totalDash / 2);
        $rightDash  = $totalDash - $leftDash;

        return $theme->border->apply(str_repeat('─', $leftDash))
             . ' ' . $label . ' '
             . $theme->border->apply(str_repeat('─', $rightDash));
    }
}
