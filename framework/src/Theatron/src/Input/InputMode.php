<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

use Phalanx\Theatron\Style\Color;

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
