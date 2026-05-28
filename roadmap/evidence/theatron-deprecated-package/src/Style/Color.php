<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Style;

final class Color
{
    private const array NAMED = [
        'black' => [0, 0, 0, 0],
        'red' => [1, 0, 0, 0],
        'green' => [2, 0, 0, 0],
        'yellow' => [3, 0, 0, 0],
        'blue' => [4, 0, 0, 0],
        'magenta' => [5, 0, 0, 0],
        'cyan' => [6, 0, 0, 0],
        'white' => [7, 0, 0, 0],
        'bright-black' => [8, 0, 0, 0],
        'gray' => [8, 0, 0, 0],
        'bright-red' => [9, 0, 0, 0],
        'bright-green' => [10, 0, 0, 0],
        'bright-yellow' => [11, 0, 0, 0],
        'bright-blue' => [12, 0, 0, 0],
        'bright-magenta' => [13, 0, 0, 0],
        'bright-cyan' => [14, 0, 0, 0],
        'bright-white' => [15, 0, 0, 0],
    ];

    private function __construct(
        private ColorKind $kind,
        private int $r = 0,
        private int $g = 0,
        private int $b = 0,
        private int $index = 0,
    ) {}

    public static function rgb(int $r, int $g, int $b): self
    {
        return new self(ColorKind::Rgb, $r, $g, $b);
    }

    public static function hex(string $hex): self
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return new self(ColorKind::Rgb, (int) $r, (int) $g, (int) $b);
    }

    public static function indexed(int $index): self
    {
        return new self(ColorKind::Indexed, index: $index);
    }

    public static function named(string $name): self
    {
        $entry = self::NAMED[strtolower($name)] ?? null;

        if ($entry === null) {
            throw new \InvalidArgumentException("Unknown color name: {$name}");
        }

        return new self(ColorKind::Named, index: $entry[0]);
    }

    public function toSgr(ColorMode $mode, bool $foreground): string
    {
        $base = $foreground ? 30 : 40;

        return match ($mode) {
            ColorMode::Ansi24 => $this->toSgrTruecolor($foreground),
            ColorMode::Ansi8 => $this->toSgr256($foreground),
            ColorMode::Ansi4 => $this->toSgr16($base),
        };
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->r === $other->r
            && $this->g === $other->g
            && $this->b === $other->b
            && $this->index === $other->index;
    }

    public static function red(): self { return self::named('red'); }
    public static function green(): self { return self::named('green'); }
    public static function blue(): self { return self::named('blue'); }
    public static function yellow(): self { return self::named('yellow'); }
    public static function magenta(): self { return self::named('magenta'); }
    public static function cyan(): self { return self::named('cyan'); }
    public static function white(): self { return self::named('white'); }
    public static function black(): self { return self::named('black'); }
    public static function gray(): self { return self::named('gray'); }
    public static function brightWhite(): self { return self::named('bright-white'); }
    public static function brightRed(): self { return self::named('bright-red'); }
    public static function brightGreen(): self { return self::named('bright-green'); }
    public static function brightYellow(): self { return self::named('bright-yellow'); }
    public static function brightBlue(): self { return self::named('bright-blue'); }
    public static function brightMagenta(): self { return self::named('bright-magenta'); }
    public static function brightCyan(): self { return self::named('bright-cyan'); }

    private function toSgrTruecolor(bool $foreground): string
    {
        [$r, $g, $b] = $this->toRgb();
        $prefix = $foreground ? '38' : '48';

        return "{$prefix};2;{$r};{$g};{$b}";
    }

    private function toSgr256(bool $foreground): string
    {
        $prefix = $foreground ? '38' : '48';

        if ($this->kind === ColorKind::Indexed || $this->kind === ColorKind::Named) {
            return "{$prefix};5;{$this->index}";
        }

        $idx = self::rgbTo256($this->r, $this->g, $this->b);

        return "{$prefix};5;{$idx}";
    }

    private function toSgr16(int $base): string
    {
        if ($this->kind === ColorKind::Named || $this->kind === ColorKind::Indexed) {
            $idx = $this->index;

            if ($idx < 8) {
                return (string) ($base + $idx);
            }

            if ($idx < 16) {
                return (string) ($base + 60 + $idx - 8);
            }

            $idx = self::rgbTo16(...self::index256ToRgb($idx));

            return $idx < 8 ? (string) ($base + $idx) : (string) ($base + 60 + $idx - 8);
        }

        $idx = self::rgbTo16($this->r, $this->g, $this->b);

        return $idx < 8 ? (string) ($base + $idx) : (string) ($base + 60 + $idx - 8);
    }

    /** @return array{int, int, int} */
    private function toRgb(): array
    {
        return match ($this->kind) {
            ColorKind::Rgb => [$this->r, $this->g, $this->b],
            ColorKind::Named, ColorKind::Indexed => self::index256ToRgb($this->index),
        };
    }

    private static function rgbTo256(int $r, int $g, int $b): int
    {
        if ($r === $g && $g === $b) {
            if ($r < 8) return 16;
            if ($r > 248) return 231;

            return (int) round(($r - 8) / 247 * 24) + 232;
        }

        return 16
            + (int) (36 * round($r / 255 * 5))
            + (int) (6 * round($g / 255 * 5))
            + (int) round($b / 255 * 5);
    }

    private static function rgbTo16(int $r, int $g, int $b): int
    {
        $value = max($r, $g, $b);

        if ($value === 0) {
            return 0;
        }

        $idx = (int) (round($b / 255) << 2 | round($g / 255) << 1 | round($r / 255));

        if ($value > 170) {
            $idx += 8;
        }

        return $idx;
    }

    /** @return array{int, int, int} */
    private static function index256ToRgb(int $index): array
    {
        if ($index < 16) {
            return match ($index) {
                0 => [0, 0, 0],
                1 => [128, 0, 0],
                2 => [0, 128, 0],
                3 => [128, 128, 0],
                4 => [0, 0, 128],
                5 => [128, 0, 128],
                6 => [0, 128, 128],
                7 => [192, 192, 192],
                8 => [128, 128, 128],
                9 => [255, 0, 0],
                10 => [0, 255, 0],
                11 => [255, 255, 0],
                12 => [0, 0, 255],
                13 => [255, 0, 255],
                14 => [0, 255, 255],
                15 => [255, 255, 255],
            };
        }

        if ($index < 232) {
            $idx = $index - 16;
            $r = (int) (($idx / 36) % 6 * 51);
            $g = (int) (($idx / 6) % 6 * 51);
            $b = (int) ($idx % 6 * 51);

            return [$r, $g, $b];
        }

        $gray = ($index - 232) * 10 + 8;

        return [$gray, $gray, $gray];
    }
}
