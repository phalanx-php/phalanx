<?php

declare(strict_types=1);

namespace Phalanx\Console\Style;

/**
 * Immutable style builder. Wraps text with ANSI open/close sequences.
 *
 * Every method returns a new instance — the original is unchanged.
 * apply() always closes with \033[0m. Never leave a style open;
 * unclosed styles bleed into subsequent terminal output.
 */
final class Style
{
    /** @param list<Modifier> $modifiers */
    private function __construct(
        private readonly ?Color $fg = null,
        private readonly ?Color $bg = null,
        private readonly array $modifiers = [],
    ) {}

    public static function new(): self
    {
        return new self();
    }

    /** @param string|int|array{0:int,1:int,2:int} $color */
    public function fg(string|int|array $color): self
    {
        return new self(Color::of($color), $this->bg, $this->modifiers);
    }

    /** @param string|int|array{0:int,1:int,2:int} $color */
    public function bg(string|int|array $color): self
    {
        return new self($this->fg, Color::of($color), $this->modifiers);
    }

    public function bold(): self
    {
        return $this->with(Modifier::Bold);
    }

    public function dim(): self
    {
        return $this->with(Modifier::Dim);
    }

    public function italic(): self
    {
        return $this->with(Modifier::Italic);
    }

    public function underline(): self
    {
        return $this->with(Modifier::Underline);
    }

    public function inverse(): self
    {
        return $this->with(Modifier::Inverse);
    }

    public function strike(): self
    {
        return $this->with(Modifier::Strike);
    }

    /** Wraps $text with open/close sequences. Always closes with RESET. */
    public function apply(string $text): string
    {
        $open = $this->open();
        if ($open === '') {
            return $text;
        }
        return $open . $text . Ansi::RESET;
    }

    /** Returns the opening SGR sequence only (fg + bg + modifiers). */
    public function open(): string
    {
        $seq = '';

        foreach ($this->modifiers as $mod) {
            $seq .= $mod->open();
        }

        if ($this->fg !== null) {
            $seq .= $this->fg->fg();
        }

        if ($this->bg !== null) {
            $seq .= $this->bg->bg();
        }

        return $seq;
    }

    public function close(): string
    {
        return Ansi::RESET;
    }

    private function with(Modifier $modifier): self
    {
        if (in_array($modifier, $this->modifiers, true)) {
            return $this;
        }
        return new self($this->fg, $this->bg, [...$this->modifiers, $modifier]);
    }
}
