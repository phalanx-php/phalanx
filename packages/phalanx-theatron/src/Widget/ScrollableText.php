<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Widget;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Widget\Text\Line;
use Phalanx\Theatron\Widget\Text\Span;

final class ScrollableText implements Widget
{
    /** @var list<Line> */
    private array $lines = [];
    private int $scrollOffset = 0;
    private bool $followTail = true;

    private Style $baseStyle;

    public function __construct(
        ?Style $baseStyle = null,
        private int $maxLines = 10_000,
    ) {
        $this->baseStyle = $baseStyle ?? Style::new();
    }

    public int $lineCount {
        get => count($this->lines);
    }

    public bool $isFollowingTail {
        get => $this->followTail;
    }

    public function append(string $text, ?Style $style = null): void
    {
        $style ??= $this->baseStyle;

        foreach (explode("\n", $text) as $raw) {
            $this->lines[] = Line::styled($raw, $style);
        }

        self::enforceLimit($this->lines, $this->maxLines);

        if ($this->followTail) {
            $this->scrollOffset = max(0, count($this->lines));
        }
    }

    public function appendLine(Line $line): void
    {
        $this->lines[] = $line;
        self::enforceLimit($this->lines, $this->maxLines);

        if ($this->followTail) {
            $this->scrollOffset = max(0, count($this->lines));
        }
    }

    public function appendToken(string $token, ?Style $style = null): void
    {
        $style ??= $this->baseStyle;
        $parts = explode("\n", $token);

        if ($this->lines === []) {
            $this->lines[] = Line::styled($parts[0], $style);
        } else {
            $lastIdx = count($this->lines) - 1;
            $this->lines[$lastIdx] = $this->lines[$lastIdx]->append(Span::styled($parts[0], $style));
        }

        for ($i = 1; $i < count($parts); $i++) {
            $this->lines[] = Line::styled($parts[$i], $style);
        }

        self::enforceLimit($this->lines, $this->maxLines);

        if ($this->followTail) {
            $this->scrollOffset = max(0, count($this->lines));
        }
    }

    public function scrollUp(int $n = 1): void
    {
        $this->scrollOffset = max(0, $this->scrollOffset - $n);
        $this->followTail = false;
    }

    public function scrollDown(int $n = 1): void
    {
        $this->scrollOffset += $n;
    }

    public function scrollToBottom(): void
    {
        $this->scrollOffset = max(0, count($this->lines));
        $this->followTail = true;
    }

    public function clear(): void
    {
        $this->lines = [];
        $this->scrollOffset = 0;
        $this->followTail = true;
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height === 0 || $area->width === 0) {
            return;
        }

        if (!$this->baseStyle->isEmpty) {
            $buffer->fill($area, $this->baseStyle);
        }

        $totalLines = count($this->lines);

        $startLine = $this->followTail
            ? max(0, $totalLines - $area->height)
            : min($this->scrollOffset, max(0, $totalLines - $area->height));

        $visibleLines = array_slice($this->lines, $startLine, $area->height);

        foreach ($visibleLines as $i => $line) {
            $y = $area->y + $i;

            if ($y >= $area->bottom) {
                break;
            }

            $buffer->putLine($area->x, $y, $line, $area->width);
        }
    }

    /** @param list<Line> $lines */
    private static function enforceLimit(array &$lines, int $max): void
    {
        $excess = count($lines) - $max;

        if ($excess > 0) {
            $lines = array_values(array_slice($lines, $excess));
        }
    }
}
