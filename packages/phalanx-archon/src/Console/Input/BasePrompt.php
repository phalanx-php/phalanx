<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Input;

use Closure;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\FormRevertedException;
use Phalanx\Scope\Disposable;
use Phalanx\Scope\Suspendable;

/**
 * Abstract base for all interactive prompts.
 *
 * State machine: initial -> active -> (error -> active)* -> submit | cancel | revert
 *
 * Synchronous flow: prompt() returns the answered value directly. Each loop
 * iteration calls $reader->nextKey($scope), which suspends the calling
 * coroutine inside Aegis ConsoleInput::read until a key arrives. On submit
 * the prompt returns the value; on cancel it throws CancelledException;
 * on revert it throws FormRevertedException so Form/Accordion can rewind.
 *
 * Subclasses implement:
 *   renderActive()   - full prompt string while accepting input
 *   renderAnswered() - compact "frozen" state written permanently on submit
 *   handleKey()      - mutate subclass state in response to a key string
 *
 * Validation: pass $validate(mixed $value): ?string - null = valid, string = error.
 * After the first failed submit, silent re-validation runs on every keypress so
 * the error clears as the user corrects the value.
 *
 * Non-TTY: prompt() returns defaultValue() immediately. No rendering, no key reads.
 */
abstract class BasePrompt
{
    protected string $state   = 'initial';
    protected string $error   = '';
    protected bool $validated = false;

    protected ?Suspendable $scope = null;
    private ?int $cachedInnerWidth = null;

    private bool $settled         = false;
    private mixed $submittedValue = null;
    private ?StreamOutput $output = null;

    public function __construct(
        protected readonly Theme $theme,
        protected readonly ?Closure $validate = null,
    ) {
    }

    final public function prompt(
        Suspendable&Disposable $scope,
        StreamOutput $output,
        KeyReader $reader,
    ): mixed {
        $this->output = $output;
        $this->scope  = $scope;

        if (!$reader->isInteractive) {
            return $this->defaultValue();
        }

        $this->renderFrame();

        while (!$this->isFinalState()) {
            $key = $reader->nextKey($scope);

            if ($key === '') {
                $this->state = 'cancel';
                $output->clear();
                break;
            }

            $this->processKey($key);
        }

        return match ($this->state) {
            'submit' => $this->submittedValue,
            'revert' => throw new FormRevertedException(),
            default => throw new CancelledException('Prompt cancelled'),
        };
    }

    final protected function render(): void
    {
        $this->renderFrame();
    }

    final protected function submit(mixed $value): void
    {
        if ($this->settled || !$this->runValidate($value)) {
            return;
        }

        assert($this->output !== null);
        $this->settled        = true;
        $this->state          = 'submit';
        $this->submittedValue = $value;
        $this->output->persist($this->renderAnswered());
    }

    final protected function cancel(): void
    {
        if ($this->settled) {
            return;
        }

        assert($this->output !== null);
        $this->settled = true;
        $this->state   = 'cancel';
        $this->output->clear();
    }

    final protected function revert(): void
    {
        if ($this->settled) {
            return;
        }

        assert($this->output !== null);
        $this->settled = true;
        $this->state   = 'revert';
        $this->output->update(' ');
        $this->output->clear();
    }

    final protected function width(): int
    {
        return $this->output?->width() ?? 80;
    }

    final protected function height(): int
    {
        return $this->output?->height() ?? 24;
    }

    // Computed once on first render and reused. Caps at 72 so prompts don't
    // sprawl across ultra-wide terminals.
    final protected function innerWidth(): int
    {
        if ($this->cachedInnerWidth === null) {
            $this->cachedInnerWidth = max(40, min(72, $this->width() - 4));
        }
        return $this->cachedInnerWidth;
    }

    final protected function hintLine(): string
    {
        $h = $this->hints();
        return $h !== '' ? "\n" . $this->theme->muted->apply('  ' . $h) : '';
    }

    /**
     * Render a rounded box frame around $content with $styledTitle embedded in the top border.
     * $labelText is the unstyled label - used to measure the title's column width.
     * $answered = true switches to dim border and skips the title styling guard.
     */
    final protected function buildFrame(
        string $content,
        string $styledTitle,
        string $labelText,
        int $width,
        bool $answered = false,
    ): string {
        $borderStyle = $answered
            ? $this->theme->border
            : ($this->state === 'error' ? $this->theme->error : $this->theme->accent);
        $topFill     = str_repeat('─', max(0, $width - mb_strlen($labelText) - 3));

        $top    = $borderStyle->apply('─ ') . $styledTitle . ' ' . $borderStyle->apply($topFill);
        $body   = explode("\n", $content);
        $bottom = $borderStyle->apply(str_repeat('─', $width));

        return implode("\n", [$top, ...$body, $bottom]);
    }

    abstract protected function renderActive(): string;
    abstract protected function renderAnswered(): string;
    abstract protected function handleKey(string $key): void;

    protected function defaultValue(): mixed
    {
        return null;
    }

    protected function currentValue(): mixed
    {
        return null;
    }

    protected function hints(): string
    {
        return '';
    }

    private function processKey(string $key): void
    {
        if ($key === 'ctrl-c') {
            $this->cancel();
            return;
        }

        if ($key === 'ctrl-u') {
            $this->revert();
            return;
        }

        if ($this->state === 'error') {
            $this->state = 'active';
        }

        $this->handleKey($key);

        if ($this->isFinalState()) {
            return;
        }

        if ($this->validated) {
            $this->runValidateSilent();
        }

        $this->renderFrame();
    }

    private function isFinalState(): bool
    {
        return $this->state === 'submit'
            || $this->state === 'cancel'
            || $this->state === 'revert';
    }

    private function renderFrame(): void
    {
        assert($this->output !== null);
        $frame = match ($this->state) {
            'initial', 'active', 'error', 'searching' => $this->renderActive(),
            default => $this->renderAnswered(),
        };

        $this->output->update($frame);

        if ($this->state === 'initial') {
            $this->state = 'active';
        }
    }

    private function runValidate(mixed $value): bool
    {
        if ($this->validate === null) {
            return true;
        }

        $message = ($this->validate)($value);

        if ($message === null) {
            $this->error     = '';
            $this->validated = true;
            return true;
        }

        $this->state     = 'error';
        $this->error     = $message;
        $this->validated = true;
        return false;
    }

    private function runValidateSilent(): void
    {
        if ($this->validate === null) {
            return;
        }
        $this->error = ($this->validate)($this->currentValue()) ?? '';
    }
}
