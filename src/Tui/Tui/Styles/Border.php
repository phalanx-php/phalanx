<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Styles;

enum Border
{
    case Single;
    case Double;
    case Rounded;
    case Heavy;
    case None;

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
