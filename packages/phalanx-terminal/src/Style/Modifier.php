<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Style;

enum Modifier: int
{
    case Bold = 1;
    case Dim = 2;
    case Italic = 4;
    case Underline = 8;
    case Reverse = 16;
    case Strikethrough = 32;

    public function sgr(): int
    {
        return match ($this) {
            self::Bold => 1,
            self::Dim => 2,
            self::Italic => 3,
            self::Underline => 4,
            self::Reverse => 7,
            self::Strikethrough => 9,
        };
    }

    public function sgrOff(): int
    {
        return match ($this) {
            self::Bold, self::Dim => 22,
            self::Italic => 23,
            self::Underline => 24,
            self::Reverse => 27,
            self::Strikethrough => 29,
        };
    }
}
