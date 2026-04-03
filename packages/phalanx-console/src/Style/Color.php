<?php

declare(strict_types=1);

namespace Phalanx\Console\Style;

/**
 * Terminal color descriptor. Supports three modes:
 *   - Named 16-color: string (e.g. 'red', 'bright-cyan')
 *   - 256-color: integer 0–255
 *   - 24-bit: [r, g, b] array
 *
 * Use Color::fg() / Color::bg() to generate SGR sequences.
 */
final class Color
{
    private const array NAMED_FG = [
        'black'          => 30,
        'red'            => 31,
        'green'          => 32,
        'yellow'         => 33,
        'blue'           => 34,
        'magenta'        => 35,
        'cyan'           => 36,
        'white'          => 37,
        'gray'           => 90,
        'bright-black'   => 90,
        'bright-red'     => 91,
        'bright-green'   => 92,
        'bright-yellow'  => 93,
        'bright-blue'    => 94,
        'bright-magenta' => 95,
        'bright-cyan'    => 96,
        'bright-white'   => 97,
    ];

    /** @param string|int|array{0:int,1:int,2:int} $color */
    private function __construct(
        private readonly string|int|array $color,
    ) {}

    /** @param string|int|array{0:int,1:int,2:int} $color */
    public static function of(string|int|array $color): self
    {
        return new self($color);
    }

    public function fg(): string
    {
        return match (true) {
            is_string($this->color) => self::namedFg($this->color),
            is_int($this->color)    => "\033[38;5;{$this->color}m",
            default                 => "\033[38;2;{$this->color[0]};{$this->color[1]};{$this->color[2]}m",
        };
    }

    public function bg(): string
    {
        return match (true) {
            is_string($this->color) => self::namedBg($this->color),
            is_int($this->color)    => "\033[48;5;{$this->color}m",
            default                 => "\033[48;2;{$this->color[0]};{$this->color[1]};{$this->color[2]}m",
        };
    }

    private static function namedFg(string $name): string
    {
        $code = self::NAMED_FG[$name] ?? null;
        if ($code === null) {
            return '';
        }
        return "\033[{$code}m";
    }

    private static function namedBg(string $name): string
    {
        $code = self::NAMED_FG[$name] ?? null;
        if ($code === null) {
            return '';
        }
        // bg codes: 30–37 → 40–47, 90–97 → 100–107 (both ranges shift by +10)
        return "\033[" . ($code + 10) . "m";
    }
}
