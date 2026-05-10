<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Widget;

use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;

/**
 * Renders a snippet of source code with line numbers and highlighting for a target line.
 */
final readonly class SourcePreview
{
    public function __construct(
        private Theme $theme,
        private int $contextLines = 3,
    ) {
    }

    public function render(string $file, int $line): string
    {
        if (!is_file($file)) {
            return '';
        }

        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        $total = count($lines);
        $start = max(0, $line - $this->contextLines - 1);
        $end = min($total - 1, $line + $this->contextLines - 1);

        $out = '';
        $gutterStyle = $this->theme->muted;
        $activeLineStyle = Style::new()->fg('red')->bold();
        $codeStyle = Style::new()->fg('white');

        for ($i = $start; $i <= $end; $i++) {
            $currentLine = $i + 1;
            $isActive = $currentLine === $line;

            $prefix = $isActive ? ' → ' : '   ';
            $gutter = str_pad((string) $currentLine, 4, ' ', STR_PAD_LEFT);
            $content = rtrim($lines[$i]);

            // Unify the margin with the rest of the console renderer
            $styledGutter = $isActive ? $activeLineStyle->apply($gutter) : $gutterStyle->apply($gutter);
            $styledPrefix = $isActive ? $activeLineStyle->apply($prefix) : $gutterStyle->apply($prefix);
            $styledContent = $isActive ? $codeStyle->apply($content) : $this->theme->muted->apply($content);

            $out .= "  {$styledPrefix}{$styledGutter} │ {$styledContent}\n";
        }

        return $out;
    }
}
