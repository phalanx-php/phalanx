<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Styles;

final class Style
{
    public bool $isEmpty {
        get => $this->foreground === null && $this->background === null && $this->modifierBits === 0;
    }

    private static ?self $empty = null;

    private function __construct(
        private(set) ?Color $foreground = null,
        private(set) ?Color $background = null,
        private(set) int $modifierBits = 0,
    ) {
    }

    public static function new(): self
    {
        return self::$empty ??= new self();
    }

    public static function of(?Color $fg = null, ?Color $bg = null, int $modifiers = 0): self
    {
        return new self($fg, $bg, $modifiers);
    }

    public static function reset(): self
    {
        return self::new();
    }

    public function fg(string|Color $color): self
    {
        $color = is_string($color) ? self::resolveColor($color) : $color;

        return new self($color, $this->background, $this->modifierBits);
    }

    public function bg(string|Color $color): self
    {
        $color = is_string($color) ? self::resolveColor($color) : $color;

        return new self($this->foreground, $color, $this->modifierBits);
    }

    public function bold(): self
    {
        return new self($this->foreground, $this->background, $this->modifierBits | Modifier::Bold->value);
    }

    public function dim(): self
    {
        return new self($this->foreground, $this->background, $this->modifierBits | Modifier::Dim->value);
    }

    public function italic(): self
    {
        return new self($this->foreground, $this->background, $this->modifierBits | Modifier::Italic->value);
    }

    public function underline(): self
    {
        return new self($this->foreground, $this->background, $this->modifierBits | Modifier::Underline->value);
    }

    public function reverse(): self
    {
        return new self($this->foreground, $this->background, $this->modifierBits | Modifier::Reverse->value);
    }

    public function strikethrough(): self
    {
        return new self($this->foreground, $this->background, $this->modifierBits | Modifier::Strikethrough->value);
    }

    public function patch(self $other): self
    {
        return new self(
            $other->foreground ?? $this->foreground,
            $other->background ?? $this->background,
            $this->modifierBits | $other->modifierBits,
        );
    }

    public function sgr(ColorMode $mode): string
    {
        $codes = [];

        foreach (Modifier::cases() as $mod) {
            if ($this->modifierBits & $mod->value) {
                $codes[] = (string) $mod->sgr();
            }
        }

        if ($this->foreground !== null) {
            $codes[] = $this->foreground->toSgr($mode, foreground: true);
        }

        if ($this->background !== null) {
            $codes[] = $this->background->toSgr($mode, foreground: false);
        }

        if ($codes === []) {
            return '';
        }

        return "\033[" . implode(';', $codes) . 'm';
    }

    public function equals(self $other): bool
    {
        $fgEqual = ($this->foreground === null && $other->foreground === null)
            || ($this->foreground !== null && $other->foreground !== null
                && $this->foreground->equals($other->foreground));

        $bgEqual = ($this->background === null && $other->background === null)
            || ($this->background !== null && $other->background !== null
                && $this->background->equals($other->background));

        return $fgEqual && $bgEqual && $this->modifierBits === $other->modifierBits;
    }

    public function hasModifier(Modifier $mod): bool
    {
        return ($this->modifierBits & $mod->value) !== 0;
    }

    private static function resolveColor(string $value): Color
    {
        if (str_starts_with($value, '#')) {
            return Color::hex($value);
        }

        return Color::named($value);
    }
}
