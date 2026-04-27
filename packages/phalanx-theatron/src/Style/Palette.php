<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Style;

final class Palette
{
    public static function severity(string $level): Style
    {
        return match (strtoupper($level)) {
            'CRITICAL' => Style::new()->fg('bright-white')->bg('red')->bold(),
            'HIGH' => Style::new()->fg('bright-red')->bold(),
            'MEDIUM' => Style::new()->fg('yellow'),
            'LOW' => Style::new()->fg('cyan'),
            'INFO' => Style::new()->fg('gray'),
            default => Style::new(),
        };
    }

    public static function agentColor(int $index): Color
    {
        $colors = [
            Color::blue(),
            Color::magenta(),
            Color::cyan(),
            Color::green(),
            Color::yellow(),
            Color::brightRed(),
        ];

        return $colors[$index % count($colors)];
    }

    public static function success(): Style
    {
        return Style::new()->fg('green')->bold();
    }

    public static function error(): Style
    {
        return Style::new()->fg('bright-red')->bold();
    }

    public static function warning(): Style
    {
        return Style::new()->fg('yellow')->bold();
    }

    public static function muted(): Style
    {
        return Style::new()->fg('gray');
    }

    public static function accent(): Style
    {
        return Style::new()->fg('cyan')->bold();
    }

    public static function heading(): Style
    {
        return Style::new()->fg('bright-white')->bold();
    }
}
