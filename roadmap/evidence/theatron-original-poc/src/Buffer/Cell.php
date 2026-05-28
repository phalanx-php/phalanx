<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Buffer;

use Phalanx\Theatron\Style\Style;

final class Cell
{
    public string $char = ' ';
    public Style $style;
    public bool $skipDiff = false;
    public bool $transparent = true;

    private static ?Style $defaultStyle = null;

    public function __construct()
    {
        $this->style = self::default();
    }

    public function set(string $char, Style $style): void
    {
        $this->char = $char;
        $this->style = $style;
        $this->skipDiff = false;
        $this->transparent = false;
    }

    public function reset(): void
    {
        $this->char = ' ';
        $this->style = self::default();
        $this->skipDiff = false;
        $this->transparent = true;
    }

    public function equals(self $other): bool
    {
        return $this->char === $other->char && $this->style->equals($other->style);
    }

    public function copyFrom(self $other): void
    {
        $this->char = $other->char;
        $this->style = $other->style;
        $this->skipDiff = $other->skipDiff;
        $this->transparent = $other->transparent;
    }

    private static function default(): Style
    {
        return self::$defaultStyle ??= Style::new();
    }
}
