<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Phalanx\Console\Style\Theme;

/**
 * Single-select list with viewport scrolling and an inline scrollbar.
 *
 * $options is a value → label map. The submitted value is the key.
 * The visible window is clamped to (terminal height - 6) at render time
 * so the box never overflows the screen.
 *
 * Scrollbar: only rendered when options overflow the viewport.
 *   $position = round($firstVisible / ($total - $scroll) * ($scroll - 1))
 * The scrollbar character replaces the trailing space of each visible row.
 *
 * Bindings: up/k, down/j, home, end, pageup, pagedown, enter.
 */
class SelectInput extends BasePrompt
{
    public string $activeGlyph  = '›';
    protected int $highlighted  = 0;
    protected int $firstVisible = 0;

    /** @param array<string, string> $options  value → label */
    public function __construct(
        Theme $theme,
        protected readonly string $label,
        protected array $options,
        protected readonly int $scroll = 5,
    ) {
        parent::__construct($theme);
    }

    protected function handleKey(string $key): void
    {
        $count = count($this->options);

        match ($key) {
            'up', 'k' => $this->move(-1),
            'down', 'j' => $this->move(1),
            'home' => $this->jumpTo(0),
            'end'  => $this->jumpTo($count - 1),
            'pageup'   => $this->move(-$this->visibleScrollSize()),
            'pagedown' => $this->move($this->visibleScrollSize()),
            'enter' => $this->submit(array_keys($this->options)[$this->highlighted]),
            default => null,
        };
    }

    protected function renderActive(): string
    {
        $keys    = array_keys($this->options);
        $total   = count($keys);
        $scroll  = $this->visibleScrollSize();
        $visible = array_slice($this->options, $this->firstVisible, $scroll, preserve_keys: true);
        $width   = $this->innerWidth();

        $showScrollbar = $total > $scroll;
        $scrollPos     = $showScrollbar
            ? (int) round($this->firstVisible / max(1, $total - $scroll) * ($scroll - 1))
            : -1;

        $lines    = [];
        $rowIndex = 0;
        foreach ($visible as $value => $label) {
            $absoluteIdx = $this->firstVisible + $rowIndex;
            $isActive    = $absoluteIdx === $this->highlighted;

            $prefix = $isActive
                ? $this->theme->accent->apply("  {$this->activeGlyph} ")
                : '    ';

            $labelText = $isActive
                ? $this->theme->accent->apply($label)
                : $label;

            // -4 for box margins, -4 for prefix, -1 for scrollbar column
            $innerWidth = $width - 9;
            $padded     = mb_strlen($label) > $innerWidth
                ? mb_substr($label, 0, $innerWidth - 1) . '~'
                : mb_str_pad($label, $innerWidth);

            $content = $prefix . ($isActive ? $this->theme->accent->apply($padded) : $padded);

            if ($showScrollbar) {
                $bar     = $rowIndex === $scrollPos
                    ? $this->theme->accent->apply('┃')
                    : $this->theme->border->apply('│');
                $content .= ' ' . $bar;
            }

            $lines[] = $content;
            $rowIndex++;
        }

        $title = $this->state === 'error'
            ? $this->theme->error->apply($this->label)
            : $this->theme->accent->apply($this->label);

        return $this->buildFrame(implode("\n", $lines) . $this->hintLine(), $title, $this->label, $width);
    }

    #[\Override]
    protected function hints(): string
    {
        return '↑↓ / jk navigate  enter confirm';
    }

    protected function renderAnswered(): string
    {
        $keys  = array_keys($this->options);
        $label = $this->options[$keys[$this->highlighted]] ?? '';

        return $this->buildFrame(
            '  ' . $this->theme->accent->apply($label),
            $this->theme->muted->apply($this->label),
            $this->label,
            $this->innerWidth(),
            answered: true,
        );
    }

    protected function defaultValue(): mixed
    {
        return array_key_first($this->options) ?? null;
    }

    protected function visibleScrollSize(): int
    {
        return max(1, min($this->scroll, $this->height() - 6));
    }

    private function move(int $delta): void
    {
        $this->highlighted = max(0, min(count($this->options) - 1, $this->highlighted + $delta));
        $this->adjustViewport();
    }

    private function jumpTo(int $index): void
    {
        $this->highlighted  = $index;
        $scroll             = $this->visibleScrollSize();
        $this->firstVisible = $index === 0 ? 0 : max(0, count($this->options) - $scroll);
    }

    private function adjustViewport(): void
    {
        $scroll = $this->visibleScrollSize();

        if ($this->highlighted < $this->firstVisible) {
            $this->firstVisible = $this->highlighted;
        } elseif ($this->highlighted >= $this->firstVisible + $scroll) {
            $this->firstVisible = $this->highlighted - $scroll + 1;
        }
    }

}
