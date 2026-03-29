<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;

final class Table implements Widget
{
    /** @var list<string> */
    private array $headers;

    /** @var list<list<string>> */
    private array $rows;

    private Style $headerStyle;
    private Style $rowStyle;
    private Style $borderStyle;

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function __construct(
        array $headers,
        array $rows = [],
        ?Style $headerStyle = null,
        ?Style $rowStyle = null,
        ?Style $borderStyle = null,
    ) {
        $this->headers = $headers;
        $this->rows = $rows;
        $this->headerStyle = $headerStyle ?? Style::new()->bold();
        $this->rowStyle = $rowStyle ?? Style::new();
        $this->borderStyle = $borderStyle ?? Style::new()->dim();
    }

    public function addRow(string ...$cells): void
    {
        $this->rows[] = array_values($cells);
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height < 3 || $area->width < 5) {
            return;
        }

        $colCount = count($this->headers);

        if ($colCount === 0) {
            return;
        }

        $colWidths = self::calculateWidths($this->headers, $this->rows, $area->width, $colCount);

        $y = $area->y;

        $y = self::renderRow($buffer, $area->x, $y, $area->width, $this->headers, $colWidths, $this->headerStyle);

        if ($y < $area->bottom) {
            self::renderHorizontalLine($buffer, $area->x, $y, $area->width, $this->borderStyle);
            $y++;
        }

        foreach ($this->rows as $row) {
            if ($y >= $area->bottom) {
                break;
            }

            $paddedRow = $row;

            while (count($paddedRow) < $colCount) {
                $paddedRow[] = '';
            }

            $y = self::renderRow($buffer, $area->x, $y, $area->width, $paddedRow, $colWidths, $this->rowStyle);
        }
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @return list<int>
     */
    private static function calculateWidths(array $headers, array $rows, int $totalWidth, int $colCount): array
    {
        $maxWidths = array_map(mb_strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                if ($i < $colCount) {
                    $maxWidths[$i] = max($maxWidths[$i] ?? 0, mb_strlen($cell));
                }
            }
        }

        $separatorWidth = ($colCount - 1) * 3;
        $available = $totalWidth - $separatorWidth;
        $totalContent = array_sum($maxWidths);

        if ($totalContent <= $available) {
            return $maxWidths;
        }

        $widths = [];

        foreach ($maxWidths as $w) {
            $widths[] = max(3, (int) floor($w / $totalContent * $available));
        }

        return $widths;
    }

    /**
     * @param list<string> $cells
     * @param list<int> $widths
     */
    private static function renderRow(Buffer $buffer, int $x, int $y, int $maxWidth, array $cells, array $widths, Style $style): int
    {
        $cx = $x;

        foreach ($cells as $i => $cell) {
            $w = $widths[$i] ?? 5;

            if ($i > 0) {
                $buffer->putString($cx, $y, ' | ', Style::new()->dim());
                $cx += 3;
            }

            $text = mb_strlen($cell) > $w
                ? mb_substr($cell, 0, $w - 1) . '~'
                : str_pad($cell, $w);

            $buffer->putString($cx, $y, $text, $style);
            $cx += $w;

            if ($cx >= $x + $maxWidth) {
                break;
            }
        }

        return $y + 1;
    }

    private static function renderHorizontalLine(Buffer $buffer, int $x, int $y, int $width, Style $style): void
    {
        for ($i = 0; $i < $width; $i++) {
            $buffer->set($x + $i, $y, '─', $style);
        }
    }
}
