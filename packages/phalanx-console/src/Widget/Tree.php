<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

use Phalanx\Console\Style\Style;
use Phalanx\Console\Style\Theme;

/**
 * Recursive tree renderer with box-drawing connectors.
 *
 * Accepts nested arrays where string keys become branch labels
 * and non-array values become leaf nodes.
 *
 * Output:
 *   root
 *   ├─ child-a
 *   │  ├─ grandchild-1
 *   │  └─ grandchild-2
 *   └─ child-b
 */
final class Tree
{
    /**
     * @param array<string|int, mixed> $nodes
     * @param array<string, Style>     $styleOverrides  key => Style for that node label
     */
    public static function render(
        array $nodes,
        Theme $theme,
        array $styleOverrides = [],
        string $prefix = '',
        bool $isRoot = true,
    ): string {
        $lines = [];
        $keys  = array_keys($nodes);
        $last  = count($keys) - 1;

        foreach ($keys as $i => $key) {
            $value    = $nodes[$key];
            $isLast   = $i === $last;
            $label    = is_string($key) ? $key : (string) $value;

            $connector  = $isLast ? '└─' : '├─';
            $childPfx   = $isLast ? '   ' : '│  ';

            $styledConn = $theme->border->apply($prefix . $connector . ' ');
            $nodeStyle  = $styleOverrides[$label] ?? null;
            $styledLabel = $nodeStyle ? $nodeStyle->apply($label) : $label;

            $lines[] = $styledConn . $styledLabel;

            if (is_array($value) && $value !== []) {
                $lines[] = self::render(
                    $value,
                    $theme,
                    $styleOverrides,
                    $prefix . $childPfx,
                    false,
                );
            }
        }

        return implode("\n", array_filter($lines));
    }
}
