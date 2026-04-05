<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

use Phalanx\Console\Style\Theme;

/**
 * Renders aligned key → value pairs.
 * All keys are padded to the same width so value columns align.
 *
 *   Frequency:  48.7 MHz
 *   Mode:       QAM64
 *   AGC:        82%
 */
final class KeyValue
{
    /**
     * @param array<string, string> $pairs  key => value
     */
    public static function render(array $pairs, Theme $theme): string
    {
        if ($pairs === []) {
            return '';
        }

        $maxKeyLen = max(array_map(mb_strlen(...), array_keys($pairs)));

        $lines = [];
        foreach ($pairs as $key => $value) {
            $padded  = mb_str_pad($key . ':', $maxKeyLen + 1);
            $lines[] = '  ' . $theme->label->apply($padded) . '  ' . $value;
        }

        return implode("\n", $lines);
    }
}
