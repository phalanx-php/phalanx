<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Buffer;

use Phalanx\Terminal\Style\Style;
use Phalanx\Terminal\Widget\Text\Line;

final class Buffer
{
    /** @var Cell[] */
    private array $cells;

    public int $width {
        get => $this->w;
    }

    public int $height {
        get => $this->h;
    }

    private function __construct(private int $w, private int $h)
    {
        $this->cells = self::allocateCells($this->w * $this->h);
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
        return $this->cells[$y * $this->w + $x];
    }

    public function set(int $x, int $y, string $char, Style $style): void
    {
        if ($x < 0 || $x >= $this->w || $y < 0 || $y >= $this->h) {
            return;
        }

        $this->cells[$y * $this->w + $x]->set($char, $style);
    }

    public function putString(int $x, int $y, string $text, Style $style): int
    {
        if ($y < 0 || $y >= $this->h) {
            return $x;
        }

        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $cx = $x + $i;

            if ($cx >= $this->w) {
                break;
            }

            if ($cx >= 0) {
                $this->cells[$y * $this->w + $cx]->set(mb_substr($text, $i, 1), $style);
            }
        }

        return min($x + $len, $this->w);
    }

    public function putLine(int $x, int $y, Line $line, int $maxWidth): void
    {
        if ($y < 0 || $y >= $this->h) {
            return;
        }

        $cx = $x;

        foreach ($line->spans as $span) {
            if ($cx - $x >= $maxWidth) {
                break;
            }

            $remaining = $maxWidth - ($cx - $x);
            $text = mb_strlen($span->content) > $remaining
                ? mb_substr($span->content, 0, $remaining)
                : $span->content;

            $cx = $this->putString($cx, $y, $text, $span->style);
        }
    }

    public function fill(Rect $area, Style $style): void
    {
        $clipped = $area->intersect(Rect::sized($this->w, $this->h));

        for ($y = $clipped->y; $y < $clipped->bottom; $y++) {
            for ($x = $clipped->x; $x < $clipped->right; $x++) {
                $this->cells[$y * $this->w + $x]->set(' ', $style);
            }
        }
    }

    public function resize(int $width, int $height): void
    {
        $newCells = self::allocateCells($width * $height);
        $copyW = min($this->w, $width);
        $copyH = min($this->h, $height);

        for ($y = 0; $y < $copyH; $y++) {
            for ($x = 0; $x < $copyW; $x++) {
                $newCells[$y * $width + $x]->copyFrom($this->cells[$y * $this->w + $x]);
            }
        }

        $this->cells = $newCells;
        $this->w = $width;
        $this->h = $height;
    }

    /** @return list<BufferUpdate> */
    public function diff(self $previous): array
    {
        $updates = [];
        $count = min(count($this->cells), count($previous->cells));

        for ($i = 0; $i < $count; $i++) {
            if (!$this->cells[$i]->equals($previous->cells[$i])) {
                $x = $i % $this->w;
                $y = intdiv($i, $this->w);
                $cell = $this->cells[$i];
                $updates[] = new BufferUpdate($x, $y, $cell->char, $cell->style);
            }
        }

        if (count($this->cells) > count($previous->cells)) {
            for ($i = $count; $i < count($this->cells); $i++) {
                $x = $i % $this->w;
                $y = intdiv($i, $this->w);
                $cell = $this->cells[$i];
                $updates[] = new BufferUpdate($x, $y, $cell->char, $cell->style);
            }
        }

        return $updates;
    }

    public function clear(): void
    {
        foreach ($this->cells as $cell) {
            $cell->reset();
        }
    }

    public function blit(self $source, Rect $sourceArea, int $destX, int $destY): void
    {
        $clipped = $sourceArea->intersect(Rect::sized($source->w, $source->h));

        for ($y = 0; $y < $clipped->height; $y++) {
            $dy = $destY + $y;

            if ($dy < 0 || $dy >= $this->h) {
                continue;
            }

            for ($x = 0; $x < $clipped->width; $x++) {
                $dx = $destX + $x;

                if ($dx < 0 || $dx >= $this->w) {
                    continue;
                }

                $this->cells[$dy * $this->w + $dx]->copyFrom(
                    $source->cells[($clipped->y + $y) * $source->w + ($clipped->x + $x)]
                );
            }
        }
    }

    public function swap(self $other): void
    {
        $tempCells = $this->cells;
        $tempW = $this->w;
        $tempH = $this->h;

        $this->cells = $other->cells;
        $this->w = $other->w;
        $this->h = $other->h;

        $other->cells = $tempCells;
        $other->w = $tempW;
        $other->h = $tempH;
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
}
