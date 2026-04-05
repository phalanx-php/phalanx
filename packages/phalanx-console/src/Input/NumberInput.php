<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Closure;
use Phalanx\Console\Style\Theme;

/**
 * Numeric input built on TextInput.
 *
 * Filters printable input to digits, one decimal point (float mode), one leading
 * minus. Up/Down adjust by $step. finalValue() returns int|float, not string.
 * Bounds check ($min/$max) wraps any user-supplied $validate closure.
 */
final class NumberInput extends TextInput
{
    public function __construct(
        Theme $theme,
        string $label,
        private readonly bool $float = false,
        private readonly int|float|null $min = null,
        private readonly int|float|null $max = null,
        private readonly int|float $step = 1,
        string $placeholder = '',
        int|float $default = 0,
        string $hint = '',
        ?Closure $validate = null,
    ) {
        parent::__construct(
            theme: $theme,
            label: $label,
            placeholder: $placeholder,
            default: (string) $default,
            hint: $hint,
            validate: $this->wrapValidate($validate),
            transform: null,
        );
    }

    #[\Override]
    protected function handleKey(string $key): void
    {
        match ($key) {
            'up'    => $this->adjustBy($this->step),
            'down'  => $this->adjustBy(-$this->step),
            default => $this->handleKeyFiltered($key),
        };
    }

    #[\Override]
    protected function hints(): string
    {
        return '↑↓ adjust value  enter confirm';
    }

    #[\Override]
    protected function finalValue(): int|float
    {
        $raw = $this->value !== '' ? $this->value : $this->default;
        return $this->float ? (float) $raw : (int) $raw;
    }

    #[\Override]
    protected function defaultValue(): mixed
    {
        return $this->finalValue();
    }

    private function handleKeyFiltered(string $key): void
    {
        if ($key === 'enter') {
            $this->submit($this->finalValue());
            return;
        }

        if (in_array($key, [
            'left', 'right', 'home', 'end', 'backspace', 'delete',
            'ctrl-a', 'ctrl-e', 'ctrl-b', 'ctrl-f', 'ctrl-w', 'ctrl-k',
        ], true)) {
            parent::handleKey($key);
            return;
        }

        if (mb_strlen($key) === 1 && $this->isAllowedChar($key)) {
            parent::handleKey($key);
        }
    }

    private function isAllowedChar(string $char): bool
    {
        return ctype_digit($char)
            || ($char === '-' && $this->cursor === 0 && !str_contains($this->value, '-'))
            || ($char === '.' && $this->float && !str_contains($this->value, '.'));
    }

    private function adjustBy(int|float $delta): void
    {
        $adjusted = ($this->value !== '' ? (float) $this->value : 0.0) + $delta;

        if ($this->min !== null) {
            $adjusted = max($this->min, $adjusted);
        }
        if ($this->max !== null) {
            $adjusted = min($this->max, $adjusted);
        }

        $this->value = $this->float
            ? rtrim(rtrim(number_format($adjusted, 10, '.', ''), '0'), '.')
            : (string) (int) $adjusted;

        $this->cursor = mb_strlen($this->value);
    }

    private function wrapValidate(?Closure $userValidate): Closure
    {
        $min   = $this->min;
        $max   = $this->max;
        $float = $this->float;

        return static function (mixed $value) use ($min, $max, $float, $userValidate): ?string {
            $n = $float ? (float) $value : (int) $value;

            if ($min !== null && $n < $min) {
                return "Must be at least {$min}";
            }
            if ($max !== null && $n > $max) {
                return "Must be at most {$max}";
            }

            return $userValidate !== null ? $userValidate($n) : null;
        };
    }
}
