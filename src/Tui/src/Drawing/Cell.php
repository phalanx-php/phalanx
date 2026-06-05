<?php

declare(strict_types=1);

namespace Phalanx\Tui\Drawing;

use Phalanx\Tui\Styles\Style;

final class Cell
{
    private(set) string $char = ' ';
    private(set) Style $style;
    private(set) bool $skipDiff = false;
    private(set) bool $transparent = true;

    public function __construct()
    {
        $this->style = Style::new();
    }

    public function set(string $char, Style $style): void
    {
        $this->char = $char;
        $this->style = $style;
        $this->skipDiff = false;
        $this->transparent = false;
    }

    public function setWidePlaceholder(Style $style): void
    {
        $this->char = '';
        $this->style = $style;
        $this->skipDiff = true;
        $this->transparent = false;
    }

    public function dim(): void
    {
        if ($this->transparent) {
            return;
        }

        $this->style = $this->style->dim();
    }

    public function scrim(Style $style): void
    {
        if ($this->transparent) {
            $this->set(' ', $style);

            return;
        }

        $this->style = $this->style->dim()->patch($style);
    }

    public function reset(): void
    {
        $this->char = ' ';
        $this->style = Style::new();
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
}
