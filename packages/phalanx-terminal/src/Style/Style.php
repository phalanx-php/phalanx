<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Style;

final class Style
{
    private function __construct(
        private ?Color $fg = null,
        private ?Color $bg = null,
        private int $modifiers = 0,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public static function reset(): self
    {
        return new self();
    }

    public bool $isEmpty {
        get => $this->fg === null && $this->bg === null && $this->modifiers === 0;
    }

    public function fg(string|Color $color): self
    {
        $color = is_string($color) ? self::resolveColor($color) : $color;

        return new self($color, $this->bg, $this->modifiers);
    }

    public function bg(string|Color $color): self
    {
        $color = is_string($color) ? self::resolveColor($color) : $color;

        return new self($this->fg, $color, $this->modifiers);
    }

    public function bold(): self
    {
        return new self($this->fg, $this->bg, $this->modifiers | Modifier::Bold->value);
    }

    public function dim(): self
    {
        return new self($this->fg, $this->bg, $this->modifiers | Modifier::Dim->value);
    }

    public function italic(): self
    {
        return new self($this->fg, $this->bg, $this->modifiers | Modifier::Italic->value);
    }

    public function underline(): self
    {
        return new self($this->fg, $this->bg, $this->modifiers | Modifier::Underline->value);
    }

    public function reverse(): self
    {
        return new self($this->fg, $this->bg, $this->modifiers | Modifier::Reverse->value);
    }

    public function strikethrough(): self
    {
        return new self($this->fg, $this->bg, $this->modifiers | Modifier::Strikethrough->value);
    }

    public function patch(self $other): self
    {
        return new self(
            $other->fg ?? $this->fg,
            $other->bg ?? $this->bg,
            $this->modifiers | $other->modifiers,
        );
    }

    public function sgr(ColorMode $mode): string
    {
        $codes = [];

        foreach (Modifier::cases() as $mod) {
            if ($this->modifiers & $mod->value) {
                $codes[] = (string) $mod->sgr();
            }
        }

        if ($this->fg !== null) {
            $codes[] = $this->fg->toSgr($mode, foreground: true);
        }

        if ($this->bg !== null) {
            $codes[] = $this->bg->toSgr($mode, foreground: false);
        }

        if ($codes === []) {
            return '';
        }

        return "\033[" . implode(';', $codes) . 'm';
    }

    public function equals(self $other): bool
    {
        $fgEqual = ($this->fg === null && $other->fg === null)
            || ($this->fg !== null && $other->fg !== null && $this->fg->equals($other->fg));

        $bgEqual = ($this->bg === null && $other->bg === null)
            || ($this->bg !== null && $other->bg !== null && $this->bg->equals($other->bg));

        return $fgEqual && $bgEqual && $this->modifiers === $other->modifiers;
    }

    public function hasModifier(Modifier $mod): bool
    {
        return ($this->modifiers & $mod->value) !== 0;
    }

    private static function resolveColor(string $value): Color
    {
        if (str_starts_with($value, '#')) {
            return Color::hex($value);
        }

        return Color::named($value);
    }
}
