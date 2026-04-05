<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Phalanx\Console\Style\Theme;

/**
 * Yes/No confirmation prompt. Returns bool.
 *
 * Bindings: left/h/ctrl-b → Yes, right/l/ctrl-f → No, tab → toggle,
 *           y → submit Yes, n → submit No, enter → submit highlighted choice.
 */
final class ConfirmInput extends BasePrompt
{
    private bool $confirmed;

    public function __construct(
        Theme $theme,
        private readonly string $label,
        private readonly bool $default = true,
    ) {
        parent::__construct($theme);
        $this->confirmed = $default;
    }

    protected function handleKey(string $key): void
    {
        match ($key) {
            'left', 'h', 'ctrl-b'  => $this->confirmed = true,
            'right', 'l', 'ctrl-f' => $this->confirmed = false,
            'tab'                  => $this->confirmed = !$this->confirmed,
            'y'                    => $this->submitNow(true),
            'n'                    => $this->submitNow(false),
            'enter'                => $this->submit($this->confirmed),
            default                => null,
        };
    }

    protected function renderActive(): string
    {
        $yes = $this->confirmed
            ? $this->theme->accent->apply(' ● Yes ')
            : $this->theme->muted->apply(' ○ Yes ');

        $no = !$this->confirmed
            ? $this->theme->accent->apply(' ● No  ')
            : $this->theme->muted->apply(' ○ No  ');

        $title = $this->state === 'error'
            ? $this->theme->error->apply($this->label)
            : $this->theme->accent->apply($this->label);

        return $this->buildFrame(
            "  {$yes}  {$no}\n" . $this->theme->hint->apply('  ← → to select, enter to confirm'),
            $title,
            $this->label,
            $this->innerWidth(),
        );
    }

    protected function renderAnswered(): string
    {
        $answer = $this->confirmed
            ? $this->theme->success->apply('Yes')
            : $this->theme->error->apply('No');

        return $this->buildFrame(
            "  {$answer}",
            $this->theme->muted->apply($this->label),
            $this->label,
            $this->innerWidth(),
            answered: true,
        );
    }

    protected function defaultValue(): mixed
    {
        return $this->default;
    }

    private function submitNow(bool $value): void
    {
        $this->confirmed = $value;
        $this->submit($value);
    }
}
