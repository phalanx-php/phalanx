<?php

declare(strict_types=1);

namespace Phalanx\Console\Style;

/**
 * Semantic style tokens. Widgets and prompts reference roles, not raw colors.
 * Swap the entire visual language by binding a different Theme in your ServiceBundle.
 */
final class Theme
{
    public function __construct(
        private(set) Style $success,
        private(set) Style $warning,
        private(set) Style $error,
        private(set) Style $muted,
        private(set) Style $accent,
        private(set) Style $label,
        private(set) Style $hint,
        private(set) Style $border,
        private(set) Style $active,
    ) {
    }

    public static function default(): self
    {
        return new self(
            success: Style::new()->fg('green')->bold(),
            warning: Style::new()->fg('yellow'),
            error: Style::new()->fg('red')->bold(),
            muted: Style::new()->dim(),
            accent: Style::new()->fg('cyan'),
            label: Style::new()->bold(),
            hint: Style::new()->dim()->italic(),
            border: Style::new()->dim(),
            active: Style::new()->fg('cyan'),
        );
    }
}
