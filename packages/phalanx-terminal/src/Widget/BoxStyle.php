<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

enum BoxStyle: string
{
    case Single = 'single';
    case Double = 'double';
    case Rounded = 'rounded';
    case Heavy = 'heavy';
    case None = 'none';

    /** @return array{string, string, string, string, string, string} [tl, tr, bl, br, h, v] */
    public function chars(): array
    {
        return match ($this) {
            self::Single => ['┌', '┐', '└', '┘', '─', '│'],
            self::Double => ['╔', '╗', '╚', '╝', '═', '║'],
            self::Rounded => ['╭', '╮', '╰', '╯', '─', '│'],
            self::Heavy => ['┏', '┓', '┗', '┛', '━', '┃'],
            self::None => [' ', ' ', ' ', ' ', ' ', ' '],
        };
    }
}
