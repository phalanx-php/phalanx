<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Closure;
use Phalanx\Console\Style\Theme;

/**
 * Single-line text input with a virtual cursor.
 *
 * All string indexing uses codepoint offsets (mb_str_split / mb_substr).
 * strlen/substr index bytes and corrupt multi-byte characters.
 *
 * Bindings: left/ctrl-b, right/ctrl-f, home/ctrl-a, end/ctrl-e,
 *           backspace, delete, ctrl-w (delete word left),
 *           ctrl-k (erase to end), enter (submit), printable char (insert).
 *           ctrl-u is reserved at the BasePrompt level for form backward navigation.
 */
class TextInput extends BasePrompt
{
    protected string $value = '';
    protected int $cursor   = 0;

    public function __construct(
        Theme $theme,
        protected readonly string $label,
        protected readonly string $placeholder = '',
        protected readonly string $default = '',
        protected readonly string $hint = '',
        ?Closure $validate = null,
        protected readonly ?Closure $transform = null,
    ) {
        parent::__construct($theme, $validate);
        $this->value  = $default;
        $this->cursor = mb_strlen($default);
    }

    protected function handleKey(string $key): void
    {
        $len = mb_strlen($this->value);

        match ($key) {
            'left', 'ctrl-b'        => $this->cursor = max(0, $this->cursor - 1),
            'right', 'ctrl-f'       => $this->cursor = min($len, $this->cursor + 1),
            'alt-left', 'alt-right' => $key === 'alt-left' ? $this->moveWordLeft() : $this->moveWordRight(),
            'home', 'ctrl-a'        => $this->cursor = 0,
            'end', 'ctrl-e'         => $this->cursor = $len,
            'backspace'             => $this->deleteLeft(),
            'delete'                => $this->deleteRight(),
            'ctrl-w'                => $this->deleteWordLeft(),
            'ctrl-k'                => $this->value = mb_substr($this->value, 0, $this->cursor),
            'enter'                 => $this->submit($this->finalValue()),
            'space'                 => $this->insertChar(' '),
            default                 => $this->insertChar($key),
        };
    }

    protected function renderActive(): string
    {
        $innerWidth = $this->innerWidth();
        $title      = $this->state === 'error'
            ? $this->theme->error->apply($this->label)
            : $this->theme->accent->apply($this->label);
        $content    = '  ' . $this->valueWithCursor($innerWidth - 4);

        if ($this->hint !== '') {
            $content .= "\n" . $this->theme->hint->apply('  ' . $this->hint);
        }

        if ($this->state === 'error' && $this->error !== '') {
            $content .= "\n" . $this->theme->error->apply('  ! ' . $this->error);
        }

        $content .= $this->hintLine();

        return $this->buildFrame($content, $title, $this->label, $innerWidth);
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

    protected function defaultValue(): mixed
    {
        return $this->finalValue();
    }

    protected function currentValue(): mixed
    {
        return $this->value !== '' ? $this->value : $this->default;
    }

    protected function valueWithCursor(int $maxWidth): string
    {
        if ($this->value === '') {
            return $this->placeholder !== ''
                ? $this->theme->muted->apply($this->placeholder)
                : "\033[7m \033[27m";
        }

        $chars = mb_str_split($this->value);

        // Append space target when cursor sits after the last character.
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

    protected function finalValue(): string|int|float
    {
        $val = $this->value !== '' ? $this->value : $this->default;
        return $this->transform !== null ? (string) ($this->transform)($val) : $val;
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
    }

    private function deleteRight(): void
    {
        $chars = mb_str_split($this->value);
        if ($this->cursor >= count($chars)) {
            return;
        }
        array_splice($chars, $this->cursor, 1);
        $this->value = implode('', $chars);
    }

    #[\Override]
    protected function hints(): string
    {
        return 'alt-← → word  ctrl-w del word  ctrl-k del to end';
    }

    private function moveWordLeft(): void
    {
        $pos = $this->cursor;
        while ($pos > 0 && !ctype_alnum(mb_substr($this->value, $pos - 1, 1))) {
            $pos--;
        }
        while ($pos > 0 && ctype_alnum(mb_substr($this->value, $pos - 1, 1))) {
            $pos--;
        }
        $this->cursor = $pos;
    }

    private function moveWordRight(): void
    {
        $len = mb_strlen($this->value);
        $pos = $this->cursor;
        while ($pos < $len && ctype_alnum(mb_substr($this->value, $pos, 1))) {
            $pos++;
        }
        while ($pos < $len && !ctype_alnum(mb_substr($this->value, $pos, 1))) {
            $pos++;
        }
        $this->cursor = $pos;
    }

    private function deleteWordLeft(): void
    {
        if ($this->cursor === 0) {
            return;
        }
        $chars = mb_str_split($this->value);
        $pos   = $this->cursor;

        while ($pos > 0 && $chars[$pos - 1] === ' ') {
            $pos--;
        }
        while ($pos > 0 && $chars[$pos - 1] !== ' ') {
            $pos--;
        }

        array_splice($chars, $pos, $this->cursor - $pos);
        $this->value  = implode('', $chars);
        $this->cursor = $pos;
    }
}
