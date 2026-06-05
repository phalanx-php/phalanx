<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Inputs;

use Phalanx\Tui\Tui\Styles\Color;

enum InputMode: string
{
    case Normal = 'normal';
    case Insert = 'insert';

    public function label(): string
    {
        return match ($this) {
            self::Normal => ' NORMAL ',
            self::Insert => ' INSERT ',
        };
    }

    public function color(): Color
    {
        return match ($this) {
            self::Normal => Color::brightCyan(),
            self::Insert => Color::brightGreen(),
        };
    }
}
