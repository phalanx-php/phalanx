<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Drawing;

use Phalanx\Theatron\Tui\Styles\Line;
use Phalanx\Theatron\Tui\Styles\Style;

final class Buffer
{
    /** @var Cell[] */
    private array $cells;

    private function __construct(private(set) int $width, private(set) int $height)
    {
        $this->cells = self::allocateCells($this->width * $this->height);
    }

    public static function empty(int $width, int $height): self
    {
        return new self($width, $height);
    }

    public static function filled(int $width, int $height, string $char, Style $style): self
    {
        $buf = new self($width, $height);
        $count = $width * $height;

        for ($i = 0; $i < $count; $i++) {
            $buf->cells[$i]->set($char, $style);
        }

        return $buf;
    }

    public function get(int $x, int $y): Cell
    {
        return $this->cells[$y * $this->width + $x];
    }

    public function set(int $x, int $y, string $char, Style $style): void
    {
        if ($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
            return;
        }

        $this->cells[$y * $this->width + $x]->set($char, $style);
    }

    public function putString(int $x, int $y, string $text, Style $style): int
    {
        if ($y < 0 || $y >= $this->height) {
            return $x;
        }

        $ascii = !preg_match('/[^\x00-\x7F]/', $text);
        $base = $y * $this->width;

        if ($ascii) {
            $len = strlen($text);

            for ($i = 0; $i < $len; $i++) {
                $cx = $x + $i;

                if ($cx >= $this->width) {
                    break;
                }

                if ($cx >= 0) {
                    $index = $base + $cx;
                    $this->cells[$index]->set($text[$i], $this->styleForWrite($index, $style));
                }
            }

            return min($x + $len, $this->width);
        }

        $chars = mb_str_split($text);
        $cx = $x;

        foreach ($chars as $ch) {
            $cw = mb_strwidth($ch);

            if ($cx + $cw > $this->width) {
                break;
            }

            if ($cx >= 0) {
                $index = $base + $cx;
                $writeStyle = $this->styleForWrite($index, $style);

                $this->cells[$index]->set($ch, $writeStyle);

                for ($p = 1; $p < $cw; $p++) {
                    $placeholderIndex = $base + $cx + $p;
                    $this->cells[$placeholderIndex]->setWidePlaceholder(
                        $this->styleForWrite($placeholderIndex, $style)
                    );
                }
            }

            $cx += $cw;
        }

        return min($cx, $this->width);
    }

    public function putLine(int $x, int $y, Line $line, int $maxWidth): void
    {
        if ($y < 0 || $y >= $this->height) {
            return;
        }

        $cx = $x;

        foreach ($line->spans as $span) {
            if ($cx - $x >= $maxWidth) {
                break;
            }

            $remaining = $maxWidth - ($cx - $x);
            $text = mb_strwidth($span->content) > $remaining
                ? mb_strimwidth($span->content, 0, $remaining)
                : $span->content;

            $cx = $this->putString($cx, $y, $text, $span->style);
        }
    }

    public function fill(Rect $area, Style $style): void
    {
        $clipped = $area->intersect(Rect::sized($this->width, $this->height));

        for ($y = $clipped->y; $y < $clipped->bottom; $y++) {
            for ($x = $clipped->x; $x < $clipped->right; $x++) {
                $this->cells[$y * $this->width + $x]->set(' ', $style);
            }
        }
    }

    public function dim(Rect $area): void
    {
        $clipped = $area->intersect(Rect::sized($this->width, $this->height));

        for ($y = $clipped->y; $y < $clipped->bottom; $y++) {
            for ($x = $clipped->x; $x < $clipped->right; $x++) {
                $this->cells[$y * $this->width + $x]->dim();
            }
        }
    }

    public function scrim(Rect $area, Style $style): void
    {
        $clipped = $area->intersect(Rect::sized($this->width, $this->height));

        for ($y = $clipped->y; $y < $clipped->bottom; $y++) {
            for ($x = $clipped->x; $x < $clipped->right; $x++) {
                $this->cells[$y * $this->width + $x]->scrim($style);
            }
        }
    }

    public function resize(int $width, int $height): void
    {
        $newCells = self::allocateCells($width * $height);
        $copyW = min($this->width, $width);
        $copyH = min($this->height, $height);

        for ($y = 0; $y < $copyH; $y++) {
            for ($x = 0; $x < $copyW; $x++) {
                $newCells[$y * $width + $x]->copyFrom($this->cells[$y * $this->width + $x]);
            }
        }

        $this->cells = $newCells;
        $this->width = $width;
        $this->height = $height;
    }

    /** @return list<BufferUpdate> */
    public function diff(self $previous): array
    {
        $updates = [];

        foreach ($this->iterDiff($previous) as [$x, $y, $cell]) {
            $updates[] = new BufferUpdate($x, $y, $cell->char, $cell->style);
        }

        return $updates;
    }

    /** @return \Generator<int, array{int, int, Cell}> */
    public function iterDiff(self $previous): \Generator
    {
        $count = min(count($this->cells), count($previous->cells));

        for ($i = 0; $i < $count; $i++) {
            $cell = $this->cells[$i];

            if ($cell->skipDiff) {
                continue;
            }

            if (!$cell->equals($previous->cells[$i])) {
                yield [$i % $this->width, intdiv($i, $this->width), $cell];
            }
        }

        $total = count($this->cells);

        for ($i = $count; $i < $total; $i++) {
            $cell = $this->cells[$i];

            if ($cell->skipDiff) {
                continue;
            }

            yield [$i % $this->width, intdiv($i, $this->width), $cell];
        }
    }

    public function clear(): void
    {
        foreach ($this->cells as $cell) {
            $cell->reset();
        }
    }

    public function blit(self $source, Rect $sourceArea, int $destX, int $destY): void
    {
        $clipped = $sourceArea->intersect(Rect::sized($source->width, $source->height));

        for ($y = 0; $y < $clipped->height; $y++) {
            $dy = $destY + $y;

            if ($dy < 0 || $dy >= $this->height) {
                continue;
            }

            for ($x = 0; $x < $clipped->width; $x++) {
                $dx = $destX + $x;

                if ($dx < 0 || $dx >= $this->width) {
                    continue;
                }

                $this->cells[$dy * $this->width + $dx]->copyFrom(
                    $source->cells[($clipped->y + $y) * $source->width + ($clipped->x + $x)]
                );
            }
        }
    }

    /** @return Cell[] */
    public function cells(): array
    {
        return $this->cells;
    }

    public function blitFull(self $source, int $destX, int $destY): void
    {
        for ($y = 0; $y < $source->height; $y++) {
            $dy = $destY + $y;

            if ($dy < 0 || $dy >= $this->height) {
                continue;
            }

            for ($x = 0; $x < $source->width; $x++) {
                $dx = $destX + $x;

                if ($dx < 0 || $dx >= $this->width) {
                    continue;
                }

                $this->cells[$dy * $this->width + $dx]->copyFrom(
                    $source->cells[$y * $source->width + $x]
                );
            }
        }
    }

    public function blitOpaque(self $source, int $destX, int $destY): void
    {
        for ($y = 0; $y < $source->height; $y++) {
            $dy = $destY + $y;

            if ($dy < 0 || $dy >= $this->height) {
                continue;
            }

            for ($x = 0; $x < $source->width; $x++) {
                $srcCell = $source->cells[$y * $source->width + $x];

                if ($srcCell->transparent) {
                    continue;
                }

                $dx = $destX + $x;

                if ($dx < 0 || $dx >= $this->width) {
                    continue;
                }

                $this->cells[$dy * $this->width + $dx]->copyFrom($srcCell);
            }
        }
    }

    public function swap(self $other): void
    {
        $tempCells = $this->cells;
        $tempW = $this->width;
        $tempH = $this->height;

        $this->cells = $other->cells;
        $this->width = $other->width;
        $this->height = $other->height;

        $other->cells = $tempCells;
        $other->width = $tempW;
        $other->height = $tempH;
    }

    /** @return Cell[] */
    private static function allocateCells(int $count): array
    {
        $cells = [];

        for ($i = 0; $i < $count; $i++) {
            $cells[] = new Cell();
        }

        return $cells;
    }

    private function styleForWrite(int $index, Style $style): Style
    {
        $background = $this->cells[$index]->style->background;

        if ($style->background !== null || $background === null) {
            return $style;
        }

        return $style->bg($background);
    }
}
