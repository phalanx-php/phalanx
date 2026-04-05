<?php

declare(strict_types=1);

namespace Phalanx\Console\Widget;

use Phalanx\Console\Style\Style;

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
            self::Single  => ['tl' => '┌', 'tr' => '┐', 'bl' => '└', 'br' => '┘', 'h' => '─', 'v' => '│'],
            self::Double  => ['tl' => '╔', 'tr' => '╗', 'bl' => '╚', 'br' => '╝', 'h' => '═', 'v' => '║'],
            self::Heavy   => ['tl' => '┏', 'tr' => '┓', 'bl' => '┗', 'br' => '┛', 'h' => '━', 'v' => '┃'],
        };
    }
}

/**
 * Draws a border around content lines.
 *
 * Used by prompts for the "answered" state. Active border: $theme->accent.
 * Answered border: $theme->border (dim). Label becomes $theme->muted on answered.
 */
final class Box
{
    public static function render(
        string $content,
        string $title = '',
        BoxStyle $style = BoxStyle::Rounded,
        ?Style $borderStyle = null,
        int $width = 0,
    ): string {
        $chars = $style->chars();
        $lines = explode("\n", $content);

        $innerWidth = $width > 0
            ? $width - 4 // 2 for border chars + 2 for padding spaces
            : max(
                $title !== '' ? mb_strlen($title) + 2 : 0,
                ...array_map(mb_strlen(...), $lines),
            );

        $top = self::topBorder($chars, $innerWidth, $title, $borderStyle);

        $body = array_map(
            static function (string $line) use ($chars, $innerWidth, $borderStyle): string {
                $v      = $borderStyle ? $borderStyle->apply($chars['v']) : $chars['v'];
                $padded = mb_str_pad($line, $innerWidth);
                return "{$v} {$padded} {$v}";
            },
            $lines,
        );

        $h      = str_repeat($chars['h'], $innerWidth + 2);
        $bottom = $borderStyle
            ? $borderStyle->apply($chars['bl'] . $h . $chars['br'])
            : $chars['bl'] . $h . $chars['br'];

        return implode("\n", [$top, ...$body, $bottom]);
    }

    /** @param array{tl:string,tr:string,bl:string,br:string,h:string,v:string} $chars */
    private static function topBorder(array $chars, int $innerWidth, string $title, ?Style $borderStyle): string
    {
        if ($title === '') {
            $h   = str_repeat($chars['h'], $innerWidth + 2);
            $raw = $chars['tl'] . $h . $chars['tr'];
            return $borderStyle ? $borderStyle->apply($raw) : $raw;
        }

        // Title embedded: ╭─ Title ───────╮
        // Strip ANSI codes before measuring — $title may be pre-styled.
        $visible  = preg_replace('/\033\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]/', '', $title) ?? $title;
        $titleLen = mb_strlen($visible);
        $leftPad    = 2; // dash + space before title
        $remaining  = $innerWidth + 2 - $leftPad - $titleLen - 1; // 1 space after title
        $remaining  = max(0, $remaining);
        $rightPad   = str_repeat($chars['h'], $remaining);

        $raw = $chars['tl']
             . $chars['h'] . ' '
             . $title
             . ' ' . $rightPad
             . $chars['tr'];

        return $borderStyle ? $borderStyle->apply($raw) : $raw;
    }
}
