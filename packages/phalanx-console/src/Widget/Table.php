<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

use Phalanx\Console\Style\Theme;

/**
 * Streaming-capable table renderer.
 *
 * Compute widths once, then persist header + individual rows as they arrive.
 * The progress bar updates on the line below via update() while rows stream in.
 *
 * Usage:
 *   $widths = Table::computeWidths(['IP', 'Chip', 'FW'], [], $output->width());
 *   $output->persist($table->header(['IP', 'Chip', 'FW'], $widths));
 *   // per hit:
 *   $output->persist($table->row([$ip, $chip, $fw], $widths));
 *   // after done:
 *   $output->persist($table->footer($widths, 'Found 9 in 10.1s'));
 */
final class Table
{
    public function __construct(private readonly Theme $theme) {}

    /**
     * Compute optimal column widths constrained to $terminalWidth.
     * Uses proportional shrinking when content overflows.
     *
     * @param list<string>        $headers
     * @param list<list<string>>  $sampleRows
     * @return list<int>
     */
    public static function computeWidths(
        array $headers,
        array $sampleRows,
        int $terminalWidth,
        int $minColWidth = 3,
    ): array {
        $colCount = count($headers);
        if ($colCount === 0) {
            return [];
        }

        $maxWidths = array_map(mb_strlen(...), $headers);

        foreach ($sampleRows as $row) {
            foreach ($row as $i => $cell) {
                if (isset($maxWidths[$i])) {
                    $maxWidths[$i] = max($maxWidths[$i], mb_strlen((string) $cell));
                }
            }
        }

        $separatorWidth = ($colCount - 1) * 3; // ' │ ' between columns
        $indent         = 2;
        $available      = $terminalWidth - $separatorWidth - $indent;

        if (array_sum($maxWidths) <= $available) {
            return $maxWidths;
        }

        $totalContent = max(1, array_sum($maxWidths));
        return array_map(
            static fn(int $w) => max($minColWidth, (int) floor($w / $totalContent * $available)),
            $maxWidths,
        );
    }

    /**
     * Render the header row followed by a separator line.
     *
     * @param list<string> $headers
     * @param list<int>    $widths
     */
    public function header(array $headers, array $widths): string
    {
        $cells = [];
        foreach ($headers as $i => $h) {
            $w      = $widths[$i] ?? mb_strlen($h);
            $cells[] = $this->theme->label->apply(mb_str_pad($h, $w));
        }

        $sep = [];
        foreach ($widths as $w) {
            $sep[] = $this->theme->border->apply(str_repeat('─', $w));
        }

        $glue    = $this->theme->border->apply(' │ ');
        $sepGlue = $this->theme->border->apply('─┼─');

        return '  ' . implode($glue, $cells) . "\n"
             . '  ' . implode($sepGlue, $sep);
    }

    /**
     * Render one data row.
     *
     * @param list<string> $cells
     * @param list<int>    $widths
     */
    public function row(array $cells, array $widths): string
    {
        $rendered = [];
        foreach ($cells as $i => $cell) {
            $w         = $widths[$i] ?? mb_strlen((string) $cell);
            $cell      = (string) $cell;
            $cellWidth = mb_strlen($cell);

            if ($cellWidth > $w) {
                $cell = mb_substr($cell, 0, $w - 1) . '~';
            } else {
                $cell = mb_str_pad($cell, $w);
            }

            $rendered[] = $cell;
        }

        $glue = $this->theme->border->apply(' │ ');
        return '  ' . implode($glue, $rendered);
    }

    /**
     * Render a closing separator with optional summary.
     *
     * @param list<int> $widths
     */
    public function footer(array $widths, string $summary = ''): string
    {
        $sep = [];
        foreach ($widths as $w) {
            $sep[] = str_repeat('─', $w);
        }

        $line = $this->theme->border->apply('  ' . implode('─┴─', $sep));

        if ($summary !== '') {
            return $line . "\n  " . $this->theme->muted->apply($summary);
        }

        return $line;
    }
}
