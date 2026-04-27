<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Buffer;

use Phalanx\Theatron\Style\Style;

final class Cell
{
    public string $char = ' ';
    public Style $style;

    public function __construct()
    {
        $this->style = Style::new();
    }

    public function set(string $char, Style $style): void
    {
        $this->char = $char;
        $this->style = $style;
    }

    public function reset(): void
    {
        $this->char = ' ';
        $this->style = Style::new();
    }

    public function equals(self $other): bool
    {
        return $this->char === $other->char && $this->style->equals($other->style);
    }

    public function copyFrom(self $other): void
    {
        $this->char = $other->char;
        $this->style = $other->style;
    }
}
