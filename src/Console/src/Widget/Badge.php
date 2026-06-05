<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

use Phalanx\Console\Style\Style;

/**
 * Inline colored label with padding.
 * Intended for use within other widget output lines.
 *
 * Badge::render('success', Style::new()->fg('green'))  →  "\033[32m success \033[0m"
 */
final class Badge
{
    public static function render(string $label, Style $style): string
    {
        return $style->apply(" {$label} ");
    }
}
