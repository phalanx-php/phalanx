<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Highlight\Highlighter;
use Phalanx\Terminal\Highlight\PhpHighlighter;
use Phalanx\Terminal\Style\Style;
use Phalanx\Terminal\Widget\Text\Line;
use Phalanx\Terminal\Widget\Text\Span;

final class CodeBlock implements Widget
{
    private Style $lineNumberStyle;
    private Style $markerStyle;

    public function __construct(
        private string $code,
        private int $startLine = 1,
        private ?int $highlightLine = null,
        private ?Highlighter $highlighter = new PhpHighlighter(),
        ?Style $lineNumberStyle = null,
        ?Style $markerStyle = null,
    ) {
        $this->lineNumberStyle = $lineNumberStyle ?? Style::new()->dim();
        $this->markerStyle = $markerStyle ?? Style::new()->fg('red')->bold();
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height === 0 || $area->width === 0) {
            return;
        }

        $highlighted = $this->highlighter->highlight($this->code);
        $totalLines = count($highlighted);
        $lastLineNum = $this->startLine + $totalLines - 1;
        $gutterWidth = max(3, mb_strlen((string) $lastLineNum) + 1);
        $codeWidth = $area->width - $gutterWidth - 2;

        if ($codeWidth < 1) {
            return;
        }

        $visibleStart = 0;

        if ($this->highlightLine !== null) {
            $targetIdx = $this->highlightLine - $this->startLine;
            $margin = (int) floor($area->height / 2);
            $visibleStart = max(0, $targetIdx - $margin);
        }

        $visibleLines = array_slice($highlighted, $visibleStart, $area->height, true);

        $row = 0;

        foreach ($visibleLines as $idx => $line) {
            $y = $area->y + $row;

            if ($y >= $area->bottom) {
                break;
            }

            $lineNum = $this->startLine + $idx;
            $isMarked = $this->highlightLine === $lineNum;

            $marker = $isMarked ? '>' : ' ';
            $markerSt = $isMarked ? $this->markerStyle : $this->lineNumberStyle;

            $buffer->set($area->x, $y, $marker, $markerSt);

            $numStr = str_pad((string) $lineNum, $gutterWidth - 1, ' ', STR_PAD_LEFT);
            $buffer->putString($area->x + 1, $y, $numStr, $this->lineNumberStyle);

            $buffer->set($area->x + $gutterWidth, $y, '│', $this->lineNumberStyle);

            $buffer->putLine($area->x + $gutterWidth + 1, $y, $line, $codeWidth);

            $row++;
        }
    }
}
