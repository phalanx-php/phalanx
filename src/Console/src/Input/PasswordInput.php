<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Closure;
use Phalanx\Console\Style\Theme;

/**
 * Password input — renders bullet characters instead of actual input.
 *
 * renderAnswered() shows a fixed-length bullet string regardless of actual
 * password length. Variable length in scrollback leaks information.
 */
final class PasswordInput extends TextInput
{
    private const string BULLET = '•';
    private const int ANSWERED_MASK_LEN = 8;

    public function __construct(
        Theme $theme,
        string $label,
        string $hint = '',
        ?Closure $validate = null,
    ) {
        parent::__construct(
            theme: $theme,
            label: $label,
            placeholder: '',
            default: '',
            hint: $hint,
            validate: $validate,
            transform: null,
        );
    }

    #[\Override]
    protected function valueWithCursor(int $maxWidth): string
    {
        $len = mb_strlen($this->value);

        if ($len === 0) {
            return "\033[7m \033[27m";
        }

        $bullets = array_fill(0, $len, self::BULLET);

        if ($this->cursor >= $len) {
            $bullets[] = ' ';
        }

        $bullets[$this->cursor] = "\033[7m{$bullets[$this->cursor]}\033[27m";

        return implode('', $bullets);
    }

    #[\Override]
    protected function renderAnswered(): string
    {
        return $this->buildFrame(
            '  ' . $this->theme->muted->apply(str_repeat(self::BULLET, self::ANSWERED_MASK_LEN)),
            $this->theme->muted->apply($this->label),
            $this->label,
            $this->innerWidth(),
            answered: true,
        );
    }
}
