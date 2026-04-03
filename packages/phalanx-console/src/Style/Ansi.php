<?php

declare(strict_types=1);

namespace Phalanx\Console\Style;

/**
 * Raw ANSI escape sequence constants and cursor movement helpers.
 * Constants only — no instances.
 */
final class Ansi
{
    public const string RESET        = "\033[0m";

    public const string BOLD_ON      = "\033[1m";
    public const string BOLD_OFF     = "\033[22m";
    public const string DIM_ON       = "\033[2m";
    public const string DIM_OFF      = "\033[22m";
    public const string ITALIC_ON    = "\033[3m";
    public const string ITALIC_OFF   = "\033[23m";
    public const string UNDERLINE_ON = "\033[4m";
    public const string UNDERLINE_OFF = "\033[24m";
    public const string INVERSE_ON   = "\033[7m";
    public const string INVERSE_OFF  = "\033[27m";
    public const string STRIKE_ON    = "\033[9m";
    public const string STRIKE_OFF   = "\033[29m";

    public const string HIDE_CURSOR  = "\033[?25l";
    public const string SHOW_CURSOR  = "\033[?25h";

    public const string ERASE_DOWN   = "\033[J";
    public const string ERASE_LINE   = "\033[2K";

    public const string SYNC_START   = "\033[?2026h";
    public const string SYNC_END     = "\033[?2026l";

    public static function up(int $n): string
    {
        return $n > 0 ? "\033[{$n}A" : '';
    }

    public static function down(int $n): string
    {
        return $n > 0 ? "\033[{$n}B" : '';
    }

    public static function right(int $n): string
    {
        return $n > 0 ? "\033[{$n}C" : '';
    }

    public static function left(int $n): string
    {
        return $n > 0 ? "\033[{$n}D" : '';
    }

    /** Move to column $n (1-indexed). */
    public static function col(int $n): string
    {
        return "\033[{$n}G";
    }

    private function __construct() {}
}
