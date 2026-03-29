<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget\Text;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Style\Style;
use Phalanx\Terminal\Widget\Widget;

final class Paragraph implements Widget
{
    /** @var list<Line> */
    private array $lines = [];
    private int $scrollOffset = 0;

    private Style $baseStyle;

    public function __construct(
        ?Style $baseStyle = null,
        private Truncation $truncation = Truncation::Ellipsis,
    ) {
        $this->baseStyle = $baseStyle ?? Style::new();
    }

    public static function of(string $text, ?Style $style = null): self
    {
        $p = new self($style ?? Style::new());

        foreach (explode("\n", $text) as $raw) {
            $p->lines[] = Line::styled($raw, $p->baseStyle);
        }

        return $p;
    }

    public static function fromLines(Line ...$lines): self
    {
        $p = new self();
        $p->lines = array_values($lines);

        return $p;
    }

    public function addLine(Line $line): void
    {
        $this->lines[] = $line;
    }

    public function scroll(int $offset): void
    {
        $this->scrollOffset = max(0, $offset);
    }

    public int $lineCount {
        get => count($this->lines);
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height === 0 || $area->width === 0) {
            return;
        }

        if (!$this->baseStyle->isEmpty) {
            $buffer->fill($area, $this->baseStyle);
        }

        $visibleLines = array_slice($this->lines, $this->scrollOffset, $area->height);

        foreach ($visibleLines as $i => $line) {
            $y = $area->y + $i;

            if ($y >= $area->bottom) {
                break;
            }

            if ($line->width <= $area->width || $this->truncation === Truncation::None) {
                $buffer->putLine($area->x, $y, $line, $area->width);
                continue;
            }

            $truncated = self::truncateLine($line, $area->width, $this->truncation);
            $buffer->putLine($area->x, $y, $truncated, $area->width);
        }
    }

    private static function truncateLine(Line $line, int $maxWidth, Truncation $mode): Line
    {
        if ($mode === Truncation::Ellipsis) {
            return self::truncateEllipsis($line, $maxWidth);
        }

        if ($mode === Truncation::Tail) {
            return self::truncateTail($line, $maxWidth);
        }

        return $line;
    }

    private static function truncateEllipsis(Line $line, int $maxWidth): Line
    {
        if ($maxWidth <= 3) {
            return Line::plain(str_repeat('.', $maxWidth));
        }

        $target = $maxWidth - 3;
        $spans = [];
        $used = 0;

        foreach ($line->spans as $span) {
            if ($used >= $target) {
                break;
            }

            $remaining = $target - $used;

            if ($span->width <= $remaining) {
                $spans[] = $span;
                $used += $span->width;
            } else {
                $spans[] = Span::styled(mb_substr($span->content, 0, $remaining), $span->style);
                $used += $remaining;
            }
        }

        $spans[] = Span::plain('...');

        return Line::from(...$spans);
    }

    private static function truncateTail(Line $line, int $maxWidth): Line
    {
        $spans = [];
        $used = 0;

        foreach ($line->spans as $span) {
            if ($used >= $maxWidth) {
                break;
            }

            $remaining = $maxWidth - $used;

            if ($span->width <= $remaining) {
                $spans[] = $span;
                $used += $span->width;
            } else {
                $spans[] = Span::styled(mb_substr($span->content, 0, $remaining), $span->style);
                $used += $remaining;
            }
        }

        return Line::from(...$spans);
    }
}
