<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Kit;

use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Contract\AcceptsInput;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Contract\Focusable;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;

use function Phalanx\Theatron\Ui\input;

final class InputComposer implements Component, Focusable, AcceptsInput
{
    use TextInputBehavior;

    private Signal $cursor;

    private Signal $killRing;

    private Signal $selectionAnchor;

    public function __construct(
        private(set) Signal $text,
        private(set) string|Line $prompt = '> ',
        private(set) ?Style $style = null,
    ) {
        $this->cursor = new Signal(mb_strlen((string) $this->text->get()));
        $this->killRing = new Signal('');
        $this->selectionAnchor = new Signal(null);
    }

    public static function empty(string|Line $prompt = '> ', ?Style $style = null): self
    {
        return new self(new Signal(''), $prompt, $style);
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
