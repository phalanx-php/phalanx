<?php

declare(strict_types=1);

namespace Phalanx\Console\Input;

use Closure;
use Phalanx\Console\Composite\FormRevertedException;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Style\Theme;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Abstract base for all interactive prompts.
 *
 * State machine: initial → active → (error → active)* → submit | cancel
 *
 * Fiber suspension bridge: prompt() returns a PromiseInterface. The command
 * calls $scope->await($prompt->prompt($output, $input)), suspending its fiber.
 * The event loop continues — Loop::addReadStream fires on keypresses, each
 * nextKey() promise resolves, the loop() .then() chain advances state, and
 * when submit() calls $deferred->resolve() the suspended fiber resumes.
 *
 * Subclasses implement:
 *   renderActive()   — full prompt string while accepting input
 *   renderAnswered() — compact "frozen" state written permanently on submit
 *   handleKey()      — mutate subclass state in response to a key string
 *
 * Validation: pass $validate(mixed $value): ?string — null = valid, string = error.
 * After the first failed submit, silent re-validation runs on every keypress so
 * the error clears as the user corrects the value.
 *
 * Non-TTY: prompt() resolves immediately with defaultValue(). No rendering.
 */
abstract class BasePrompt
{
    protected string $state   = 'initial';
    protected string $error   = '';
    protected bool $validated = false;
    protected bool $loopOwned = false;

    private ?int $cachedInnerWidth = null;

    private bool $settled         = false;
    private ?StreamOutput $output = null;
    private ?RawInput $input      = null;
    private ?Deferred $deferred   = null;

    public function __construct(
        protected readonly Theme $theme,
        protected readonly ?Closure $validate = null,
    ) {}

    final public function prompt(StreamOutput $output, RawInput $input): PromiseInterface
    {
        $this->output   = $output;
        $this->input    = $input;
        $this->deferred = new Deferred();

        if (!$input->isTty()) {
            $this->deferred->resolve($this->defaultValue());
            return $this->deferred->promise();
        }

        $this->renderFrame();
        $this->loop();

        return $this->deferred->promise();
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

        $this->settled = true;
        $this->state   = 'submit';
        $this->output->persist($this->renderAnswered());
        $this->deferred->resolve($value);
    }

    final protected function cancel(): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->state   = 'cancel';
        $this->output->clear();
        $this->deferred->reject(new CancelledException('Prompt cancelled'));
    }

    final protected function revert(): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->state   = 'cancel';
        $this->output->update(' ');
        $this->output->clear();
        $this->deferred->reject(new FormRevertedException());
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

    final protected function hintLine(): string
    {
        $h = $this->hints();
        return $h !== '' ? "\n" . $this->theme->muted->apply('  ' . $h) : '';
    }

    /**
     * Render a rounded box frame around $content with $styledTitle embedded in the top border.
     * $labelText is the unstyled label — used to measure the title's column width.
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

    final protected function loop(): void
    {
        /**
         * Non-static: this closure is the only strong reference keeping $this alive
         * while the fiber is suspended. WeakReference would allow GC mid-interaction.
         * The cycle breaks when submit/cancel/revert resolves $deferred.
         */
        $this->input->nextKey()->then(function (string $key): void {
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

            if ($this->state === 'submit' || $this->state === 'cancel') {
                return;
            }

            if ($this->validated) {
                $this->runValidateSilent();
            }

            $this->renderFrame();

            if (!$this->loopOwned) {
                $this->loop();
            }
        });
    }

    private function renderFrame(): void
    {
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
