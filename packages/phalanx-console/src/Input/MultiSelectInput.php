<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Phalanx\Console\Style\Theme;

/**
 * Multi-select list. Inherits viewport scrolling from SelectInput.
 *
 * Submit returns list<string> of selected values (keys from $options).
 *
 * Visual states per row:
 *   ›●  active + selected    (accent, filled)
 *   ›○  active + unselected  (accent, empty)
 *    ●  inactive + selected  (plain)
 *    ○  inactive + unselected (dim)
 *
 * Bindings: space (toggle), ctrl-a (select/deselect all), enter (submit).
 * All SelectInput navigation bindings apply.
 */
final class MultiSelectInput extends SelectInput
{
    /** @var array<string, true> */
    private array $selected = [];

    /**
     * @param array<string, string> $options      value → label
     * @param list<string>          $defaultValues pre-selected values
     */
    public function __construct(
        Theme $theme,
        string $label,
        array $options,
        array $defaultValues = [],
        int $scroll = 5,
    ) {
        parent::__construct(theme: $theme, label: $label, options: $options, scroll: $scroll);
        $this->selected = array_fill_keys($defaultValues, true);
    }

    #[\Override]
    protected function handleKey(string $key): void
    {
        match ($key) {
            'space'  => $this->toggleHighlighted(),
            'ctrl-a' => $this->toggleAll(),
            'enter'  => $this->submit(array_keys($this->selected)),
            default  => parent::handleKey($key),
        };
    }

    #[\Override]
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
            $isSelected  = isset($this->selected[$value]);

            $circle  = $isSelected ? '●' : '○';
            $arrow   = $isActive ? "{$this->activeGlyph} " : '  ';
            $prefix  = $isActive
                ? $this->theme->accent->apply("  {$arrow}{$circle} ")
                : ($isSelected ? "    {$circle} " : $this->theme->muted->apply("    {$circle} "));

            $innerWidth = $width - 11; // box(4) + prefix(7)
            $padded     = mb_strlen($label) > $innerWidth
                ? mb_substr($label, 0, $innerWidth - 1) . '~'
                : mb_str_pad($label, $innerWidth);

            $labelText = $isActive ? $this->theme->accent->apply($padded) : $padded;
            $content   = $prefix . $labelText;

            if ($showScrollbar) {
                $bar     = $rowIndex === $scrollPos
                    ? $this->theme->accent->apply('┃')
                    : $this->theme->border->apply('│');
                $content .= ' ' . $bar;
            }

            $lines[] = $content;
            $rowIndex++;
        }

        $selectedCount = count($this->selected);
        $count = $this->theme->hint->apply("  {$selectedCount} selected");

        $title = $this->state === 'error'
            ? $this->theme->error->apply($this->label)
            : $this->theme->accent->apply($this->label);

        return $this->buildFrame(implode("\n", $lines) . "\n" . $count . $this->hintLine(), $title, $this->label, $width);
    }

    #[\Override]
    protected function renderAnswered(): string
    {
        $options = $this->options;
        $labels  = array_map(
            static fn(string $value) => $options[$value] ?? $value,
            array_keys($this->selected),
        );

        $count   = count($labels);
        $summary = $count === 0
            ? $this->theme->muted->apply('none selected')
            : $this->theme->accent->apply("{$count} selected") . ': ' . implode(', ', $labels);

        return $this->buildFrame('  ' . $summary, $this->theme->muted->apply($this->label), $this->label, $this->innerWidth(), answered: true);
    }

    #[\Override]
    protected function hints(): string
    {
        return '↑↓ navigate  space toggle  ctrl-a all  enter confirm';
    }

    #[\Override]
    protected function defaultValue(): mixed
    {
        return array_keys($this->selected);
    }

    private function toggleHighlighted(): void
    {
        $value = array_keys($this->options)[$this->highlighted];

        if (isset($this->selected[$value])) {
            unset($this->selected[$value]);
        } else {
            $this->selected[$value] = true;
        }
    }

    private function toggleAll(): void
    {
        $this->selected = count($this->selected) === count($this->options)
            ? []
            : array_fill_keys(array_keys($this->options), true);
    }
}
