<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Style;

final class Palette
{
    private static ?Style $success = null;
    private static ?Style $error = null;
    private static ?Style $warning = null;
    private static ?Style $muted = null;
    private static ?Style $accent = null;
    private static ?Style $heading = null;

    /** @var array<string, Style> */
    private static array $severityCache = [];

    /** @var list<Color>|null */
    private static ?array $cyclicColors = null;

    public static function severity(string $level): Style
    {
        $key = strtoupper($level);

        return self::$severityCache[$key] ??= match ($key) {
            'CRITICAL' => Style::new()->fg('bright-white')->bg('red')->bold(),
            'HIGH' => Style::new()->fg('bright-red')->bold(),
            'MEDIUM' => Style::new()->fg('yellow'),
            'LOW' => Style::new()->fg('cyan'),
            'INFO' => Style::new()->fg('gray'),
            default => Style::new(),
        };
    }

    public static function cyclicColor(int $index): Color
    {
        self::$cyclicColors ??= [
            Color::blue(),
            Color::magenta(),
            Color::cyan(),
            Color::green(),
            Color::yellow(),
            Color::brightRed(),
        ];

        return self::$cyclicColors[$index % count(self::$cyclicColors)];
    }

    public static function success(): Style
    {
        return self::$success ??= Style::new()->fg('green')->bold();
    }

    public static function error(): Style
    {
        return self::$error ??= Style::new()->fg('bright-red')->bold();
    }

    public static function warning(): Style
    {
        return self::$warning ??= Style::new()->fg('yellow')->bold();
    }

    public static function muted(): Style
    {
        return self::$muted ??= Style::new()->fg('gray');
    }

    public static function accent(): Style
    {
        return self::$accent ??= Style::new()->fg('cyan')->bold();
    }

    public static function heading(): Style
    {
        return self::$heading ??= Style::new()->fg('bright-white')->bold();
    }
}
