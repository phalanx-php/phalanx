<?php

declare(strict_types=1);

namespace Phalanx\Console\Style;

enum Modifier
{
    case Bold;
    case Dim;
    case Italic;
    case Underline;
    case Inverse;
    case Strike;

    public function open(): string
    {
        return match ($this) {
            self::Bold      => Ansi::BOLD_ON,
            self::Dim       => Ansi::DIM_ON,
            self::Italic    => Ansi::ITALIC_ON,
            self::Underline => Ansi::UNDERLINE_ON,
            self::Inverse   => Ansi::INVERSE_ON,
            self::Strike    => Ansi::STRIKE_ON,
        };
    }

    public function close(): string
    {
        return match ($this) {
            self::Bold      => Ansi::BOLD_OFF,
            self::Dim       => Ansi::DIM_OFF,
            self::Italic    => Ansi::ITALIC_OFF,
            self::Underline => Ansi::UNDERLINE_OFF,
            self::Inverse   => Ansi::INVERSE_OFF,
            self::Strike    => Ansi::STRIKE_OFF,
        };
    }
}
