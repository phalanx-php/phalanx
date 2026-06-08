<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

/**
 * Border character set selector for Box::render. Each case returns its own
 * top/bottom/left/right glyphs; the rounded variant is the default for
 * answered prompts and accordion summaries.
 */
enum BoxStyle
{
    case Rounded;
    case Single;
    case Double;
    case Heavy;

    /** @return array{tl:string,tr:string,bl:string,br:string,h:string,v:string} */
    public function chars(): array
    {
        return match ($this) {
            self::Rounded => ['tl' => '╭', 'tr' => '╮', 'bl' => '╰', 'br' => '╯', 'h' => '─', 'v' => '│'],
            self::Single => ['tl' => '┌', 'tr' => '┐', 'bl' => '└', 'br' => '┘', 'h' => '─', 'v' => '│'],
            self::Double => ['tl' => '╔', 'tr' => '╗', 'bl' => '╚', 'br' => '╝', 'h' => '═', 'v' => '║'],
            self::Heavy => ['tl' => '┏', 'tr' => '┓', 'bl' => '┗', 'br' => '┛', 'h' => '━', 'v' => '┃'],
        };
    }
}
