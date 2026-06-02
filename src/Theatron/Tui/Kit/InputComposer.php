<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Kit;

use Closure;
use Phalanx\Theatron\Tui\Core\AcceptsInput;
use Phalanx\Theatron\Tui\Core\Component;
use Phalanx\Theatron\Tui\Core\Focusable;
use Phalanx\Theatron\Tui\Core\RenderContext;
use Phalanx\Theatron\Tui\Inputs\Key;
use Phalanx\Theatron\Tui\Inputs\KeyEvent;
use Phalanx\Theatron\Tui\Reactive\Signal;
use Phalanx\Theatron\Tui\Styles\Line;
use Phalanx\Theatron\Tui\Tdom\Renderable;
use Phalanx\Theatron\Tui\Tdom\Style;

use function Phalanx\Theatron\Tui\Kit\input;

final class InputComposer implements Component, Focusable, AcceptsInput
{
    use TextInputBehavior;

    private Signal $cursor;

    private Signal $killRing;

    private Signal $selectionAnchor;

    /** @var ?Closure(string): void */
    private ?Closure $onSubmit;

    /** @param (callable(string): void)|null $onSubmit */
    public function __construct(
        private(set) Signal $text,
        private(set) string|Line $prompt = '> ',
        private(set) ?Style $style = null,
        ?callable $onSubmit = null,
    ) {
        $this->onSubmit = $onSubmit === null ? null : Closure::fromCallable($onSubmit);
        $this->cursor = new Signal(mb_strlen((string) $this->text->get()));
        $this->killRing = new Signal('');
        $this->selectionAnchor = new Signal(null);
    }

    /** @param (callable(string): void)|null $onSubmit */
    public static function empty(string|Line $prompt = '> ', ?Style $style = null, ?callable $onSubmit = null): self
    {
        return new self(new Signal(''), $prompt, $style, $onSubmit);
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        $text = (string) $this->text->get();
        $cursor = self::clamp((int) $this->cursor->get(), $text);
        [$selectionStart, $selectionEnd] = $this->selectionFor($text, $cursor);

        return input(
            value: $text,
            prompt: $this->prompt,
            cursor: $cursor,
            style: $this->style,
            selectionStart: $selectionStart,
            selectionEnd: $selectionEnd,
        );
    }

    public function handleInput(KeyEvent $event): bool
    {
        if (!$event->ctrl && !$event->alt && !$event->shift && $event->is(Key::Enter)) {
            return $this->submit();
        }

        return $this->handleTextInput($event);
    }

    protected function inputSignal(): Signal
    {
        return $this->text;
    }

    protected function inputCursorSignal(): Signal
    {
        return $this->cursor;
    }

    protected function inputKillRingSignal(): Signal
    {
        return $this->killRing;
    }

    protected function inputSelectionAnchorSignal(): Signal
    {
        return $this->selectionAnchor;
    }

    private static function clamp(int $cursor, string $text): int
    {
        return max(0, min($cursor, mb_strlen($text)));
    }

    private function submit(): bool
    {
        $text = (string) $this->text->get();

        if (trim($text) === '' || $this->onSubmit === null) {
            return true;
        }

        ($this->onSubmit)($text);

        $this->text->set('');
        $this->cursor->set(0);
        $this->selectionAnchor->set(null);

        return true;
    }

    /**
     * @return array{?int, ?int}
     */
    private function selectionFor(string $text, int $cursor): array
    {
        $anchor = $this->selectionAnchor->get();

        if (!is_int($anchor)) {
            return [null, null];
        }

        $anchor = self::clamp($anchor, $text);

        if ($anchor === $cursor) {
            return [null, null];
        }

        return [min($anchor, $cursor), max($anchor, $cursor)];
    }
}
