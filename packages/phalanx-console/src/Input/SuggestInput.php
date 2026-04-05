<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Closure;
use Phalanx\Console\Style\Theme;
use React\Promise\PromiseInterface;

/**
 * Text input with an inline suggestion overlay.
 *
 * The user types freely. Each keystroke triggers the $search closure which
 * returns suggestions. Suggestions render as a sub-box below the text field,
 * still inside the outer box — the multi-line update() erases and redraws
 * the whole thing including the suggestion list on each render.
 *
 * Tab accepts the highlighted suggestion. Escape dismisses without accepting.
 * Up/Down navigate suggestions. Enter submits the current text (not the suggestion).
 *
 * The suggestion box height is clamped to 4 rows so the outer box stays compact.
 */
final class SuggestInput extends BasePrompt
{
    private string $value       = '';
    private int $cursor         = 0;
    private ?array $suggestions = null;
    private int $highlighted    = 0;

    public function __construct(
        Theme $theme,
        private readonly string $label,
        private readonly Closure $search,
        private readonly string $placeholder = '',
        private readonly string $hint = '',
        ?Closure $validate = null,
    ) {
        parent::__construct($theme, $validate);
    }

    protected function handleKey(string $key): void
    {
        match ($key) {
            'tab'       => $this->acceptSuggestion(),
            'escape'    => $this->suggestions = null,
            'up'        => $this->highlighted = max(0, $this->highlighted - 1),
            'down'      => $this->highlighted = min(
                max(0, count($this->suggestions ?? []) - 1),
                $this->highlighted + 1,
            ),
            'enter'     => $this->submit($this->finalValue()),
            'backspace' => $this->deleteLeft(),
            'space'     => $this->insertChar(' '),
            default     => $this->insertChar($key),
        };
    }

    protected function renderActive(): string
    {
        $width      = $this->innerWidth();
        $innerWidth = $width - 4;
        $content    = '  ' . $this->valueWithCursor($innerWidth - 4);

        if ($this->hint !== '') {
            $content .= "\n" . $this->theme->hint->apply('  ' . $this->hint);
        }

        if ($this->state === 'error' && $this->error !== '') {
            $content .= "\n" . $this->theme->error->apply('  ! ' . $this->error);
        }

        if ($this->suggestions !== null && $this->suggestions !== []) {
            $content .= "\n" . $this->renderSuggestionBox($innerWidth - 2);
        }

        $content .= $this->hintLine();

        $title = $this->state === 'error'
            ? $this->theme->error->apply($this->label)
            : $this->theme->accent->apply($this->label);

        return $this->buildFrame($content, $title, $this->label, $width);
    }

    protected function renderAnswered(): string
    {
        return $this->buildFrame(
            '  ' . $this->finalValue(),
            $this->theme->muted->apply($this->label),
            $this->label,
            $this->innerWidth(),
            answered: true,
        );
    }

    #[\Override]
    protected function hints(): string
    {
        return 'tab accept suggestion  esc dismiss  enter confirm';
    }

    protected function defaultValue(): mixed
    {
        return $this->finalValue();
    }

    protected function currentValue(): mixed
    {
        return $this->value;
    }

    private function valueWithCursor(int $maxWidth): string
    {
        if ($this->value === '') {
            return $this->placeholder !== ''
                ? $this->theme->muted->apply($this->placeholder)
                : "\033[7m \033[27m";
        }

        $chars = mb_str_split($this->value);
        if ($this->cursor >= count($chars)) {
            $chars[] = ' ';
        }
        $chars[$this->cursor] = "\033[7m{$chars[$this->cursor]}\033[27m";

        $visibleLen = mb_strlen($this->value) + 1;
        if ($visibleLen <= $maxWidth) {
            return implode('', $chars);
        }

        $trimFrom = max(0, $this->cursor - $maxWidth + 2);
        return $this->theme->muted->apply('…') . implode('', array_slice($chars, $trimFrom, $maxWidth - 1));
    }

    private function renderSuggestionBox(int $width): string
    {
        $limit = min(4, count($this->suggestions));
        $sep   = $this->theme->border->apply('  ' . str_repeat('─', max(0, $width - 2)));
        $lines = [$sep];

        for ($i = 0; $i < $limit; $i++) {
            $item    = (string) $this->suggestions[$i];
            $active  = $i === $this->highlighted;
            $prefix  = $active ? $this->theme->accent->apply('  › ') : '    ';
            $lines[] = $prefix . ($active ? $this->theme->accent->apply($item) : $item);
        }

        return implode("\n", $lines);
    }

    private function acceptSuggestion(): void
    {
        if ($this->suggestions === null || $this->suggestions === []) {
            return;
        }
        $this->value       = (string) ($this->suggestions[$this->highlighted] ?? $this->value);
        $this->cursor      = mb_strlen($this->value);
        $this->suggestions = null;
        $this->triggerSearch();
    }

    private function insertChar(string $key): void
    {
        if (mb_strlen($key) !== 1 || mb_ord($key) < 32) {
            return;
        }
        $chars = mb_str_split($this->value);
        array_splice($chars, $this->cursor, 0, [$key]);
        $this->value = implode('', $chars);
        $this->cursor++;
        $this->triggerSearch();
    }

    private function deleteLeft(): void
    {
        if ($this->cursor === 0) {
            return;
        }
        $chars = mb_str_split($this->value);
        array_splice($chars, $this->cursor - 1, 1);
        $this->value = implode('', $chars);
        $this->cursor--;
        $this->triggerSearch();
    }

    private function triggerSearch(): void
    {
        $result = ($this->search)($this->value);

        if ($result instanceof PromiseInterface) {
            $this->loopOwned = true;
            /**
             * Non-static: search may resolve after loop() yields. WeakReference
             * risks GC before resolution, leaving the prompt frozen with no key handler.
             */
            $result->then(function (array $suggestions): void {
                $this->loopOwned   = false;
                $this->suggestions = $suggestions;
                $this->highlighted = 0;
                $this->render();
                $this->loop();
            });
        } else {
            $this->suggestions = (array) $result;
            $this->highlighted = 0;
        }
    }

    private function finalValue(): string
    {
        return $this->value;
    }

}
