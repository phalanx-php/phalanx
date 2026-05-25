<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Kit;

use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Reactive\Signal;

trait TextInputBehavior
{
    private ?Signal $textInputCursorSignal = null;
    private ?Signal $textInputKillRingSignal = null;
    private ?Signal $textInputSelectionAnchorSignal = null;

    abstract protected function inputSignal(): Signal;

    protected function inputCursorSignal(): Signal
    {
        if ($this->textInputCursorSignal === null) {
            $text = (string) $this->inputSignal()->get();
            $this->textInputCursorSignal = new Signal(mb_strlen($text));
        }

        return $this->textInputCursorSignal;
    }

    protected function inputKillRingSignal(): Signal
    {
        return $this->textInputKillRingSignal ??= new Signal('');
    }

    protected function inputSelectionAnchorSignal(): Signal
    {
        return $this->textInputSelectionAnchorSignal ??= new Signal(null);
    }

    protected function handleTextInput(KeyEvent $event): bool
    {
        $signal = $this->inputSignal();

        if ($this->handleAltInput($event, $signal)) {
            return true;
        }

        if ($this->handleControlInput($event, $signal)) {
            return true;
        }

        $char = $event->char();

        if ($char !== null && !$event->ctrl && !$event->alt) {
            $this->insertText($signal, $char);

            return true;
        }

        return false;
    }

    private function handleControlInput(KeyEvent $event, Signal $signal): bool
    {
        if (!$event->ctrl) {
            return match (true) {
                $event->is(Key::Backspace) => $this->deleteBeforeCursor($signal),
                $event->is(Key::Delete) => $this->deleteAtCursor($signal),
                $event->is(Key::Left) => $this->moveCursor($signal, -1, $event->shift),
                $event->is(Key::Right) => $this->moveCursor($signal, 1, $event->shift),
                $event->is(Key::Home) => $this->moveCursorToLineStart($signal),
                $event->is(Key::End) => $this->moveCursorToLineEnd($signal),
                default => false,
            };
        }

        return match (true) {
            $event->is('a') || $event->is(Key::Home) => $this->moveCursorToLineStart($signal),
            $event->is('e') || $event->is(Key::End) => $this->moveCursorToLineEnd($signal),
            $event->is('b') || $event->is(Key::Left) => $this->moveCursor($signal, -1),
            $event->is('f') || $event->is(Key::Right) => $this->moveCursor($signal, 1),
            $event->is('d') || $event->is(Key::Delete) => $this->deleteAtCursor($signal),
            $event->is('u') => $this->killToLineStart($signal),
            $event->is('k') => $this->killToLineEnd($signal),
            $event->is('w') => $this->killPreviousWord($signal),
            $event->is('y') => $this->yank($signal),
            default => false,
        };
    }

    private function handleAltInput(KeyEvent $event, Signal $signal): bool
    {
        if (!$event->alt) {
            return false;
        }

        return match (true) {
            $event->is(Key::Left) => $this->moveCursorToPreviousWord($signal, $event->shift),
            $event->is(Key::Right) => $this->moveCursorToNextWord($signal, $event->shift),
            $event->is('b') => $this->moveCursorToPreviousWord($signal),
            $event->is('f') => $this->moveCursorToNextWord($signal),
            $event->is('d') => $this->killNextWord($signal),
            $event->is(Key::Backspace) => $this->killPreviousWord($signal),
            default => false,
        };
    }

    private function splice(string $text, int $start, int $end, string $insert = ''): string
    {
        return mb_substr($text, 0, $start) . $insert . mb_substr($text, $end);
    }

    private function clampCursor(int $cursor, string $text): int
    {
        return max(0, min($cursor, mb_strlen($text)));
    }

    private function lineStart(string $text, int $cursor): int
    {
        $before = mb_substr($text, 0, $cursor);
        $lineBreak = mb_strrpos($before, "\n");

        return $lineBreak === false ? 0 : $lineBreak + 1;
    }

    private function lineEnd(string $text, int $cursor): int
    {
        $after = mb_substr($text, $cursor);
        $lineBreak = mb_strpos($after, "\n");

        return $lineBreak === false ? mb_strlen($text) : $cursor + $lineBreak;
    }

    private function previousWord(string $text, int $cursor): int
    {
        $cursor = $this->clampCursor($cursor, $text);

        while ($cursor > 0 && $this->isWhitespace(mb_substr($text, $cursor - 1, 1))) {
            $cursor--;
        }

        while ($cursor > 0 && !$this->isWhitespace(mb_substr($text, $cursor - 1, 1))) {
            $cursor--;
        }

        return $cursor;
    }

    private function nextWord(string $text, int $cursor): int
    {
        $cursor = $this->clampCursor($cursor, $text);
        $length = mb_strlen($text);

        while ($cursor < $length && $this->isWhitespace(mb_substr($text, $cursor, 1))) {
            $cursor++;
        }

        while ($cursor < $length && !$this->isWhitespace(mb_substr($text, $cursor, 1))) {
            $cursor++;
        }

        return $cursor;
    }

    private function isWhitespace(string $char): bool
    {
        return preg_match('/\s/u', $char) === 1;
    }

    private function insertText(Signal $signal, string $insert): void
    {
        $text = (string) $signal->get();
        $selection = $this->selectionRange($text);

        if ($selection !== null) {
            [$start, $end] = $selection;
            $signal->set($this->splice($text, $start, $end, $insert));
            $this->setCursor($start + mb_strlen($insert));
            $this->clearSelection();

            return;
        }

        $cursor = $this->textCursor($text);
        $signal->set($this->splice($text, $cursor, $cursor, $insert));
        $this->setCursor($cursor + mb_strlen($insert));
        $this->clearSelection();
    }

    private function deleteBeforeCursor(Signal $signal): bool
    {
        $text = (string) $signal->get();
        $selection = $this->selectionRange($text);

        if ($selection !== null) {
            [$start, $end] = $selection;
            $signal->set($this->splice($text, $start, $end));
            $this->setCursor($start);
            $this->clearSelection();

            return true;
        }

        $cursor = $this->textCursor($text);
        if ($cursor === 0) {
            return true;
        }

        $signal->set($this->splice($text, $cursor - 1, $cursor));
        $this->setCursor($cursor - 1);
        $this->clearSelection();

        return true;
    }

    private function deleteAtCursor(Signal $signal): bool
    {
        $text = (string) $signal->get();
        $selection = $this->selectionRange($text);

        if ($selection !== null) {
            [$start, $end] = $selection;
            $signal->set($this->splice($text, $start, $end));
            $this->setCursor($start);
            $this->clearSelection();

            return true;
        }

        $cursor = $this->textCursor($text);
        if ($cursor >= mb_strlen($text)) {
            return $text === '';
        }

        $signal->set($this->splice($text, $cursor, $cursor + 1));
        $this->setCursor($cursor);
        $this->clearSelection();

        return true;
    }

    private function moveCursor(Signal $signal, int $delta, bool $selecting = false): bool
    {
        $text = (string) $signal->get();
        $selection = $this->selectionRange($text);

        if (!$selecting && $selection !== null) {
            [$start, $end] = $selection;
            $this->setCursor($delta < 0 ? $start : $end, $text);
            $this->clearSelection();

            return true;
        }

        $cursor = $this->textCursor($text);
        $this->setCursor($cursor + $delta, $text);
        $this->updateSelection($text, $cursor, $selecting);

        return true;
    }

    private function moveCursorToLineStart(Signal $signal): bool
    {
        $text = (string) $signal->get();
        $this->setCursor($this->lineStart($text, $this->textCursor($text)), $text);
        $this->clearSelection();

        return true;
    }

    private function moveCursorToLineEnd(Signal $signal): bool
    {
        $text = (string) $signal->get();
        $this->setCursor($this->lineEnd($text, $this->textCursor($text)), $text);
        $this->clearSelection();

        return true;
    }

    private function moveCursorToPreviousWord(Signal $signal, bool $selecting = false): bool
    {
        $text = (string) $signal->get();
        $cursor = $this->textCursor($text);
        $this->setCursor($this->previousWord($text, $cursor), $text);
        $this->updateSelection($text, $cursor, $selecting);

        return true;
    }

    private function moveCursorToNextWord(Signal $signal, bool $selecting = false): bool
    {
        $text = (string) $signal->get();
        $cursor = $this->textCursor($text);
        $this->setCursor($this->nextWord($text, $cursor), $text);
        $this->updateSelection($text, $cursor, $selecting);

        return true;
    }

    private function killToLineStart(Signal $signal): bool
    {
        $text = (string) $signal->get();
        $selection = $this->selectionRange($text);

        if ($selection !== null) {
            return $this->killSelection($signal, $text, $selection);
        }

        $cursor = $this->textCursor($text);
        $start = $this->lineStart($text, $cursor);

        return $this->killRange($signal, $text, $start, $cursor, $start);
    }

    private function killToLineEnd(Signal $signal): bool
    {
        $text = (string) $signal->get();
        $selection = $this->selectionRange($text);

        if ($selection !== null) {
            return $this->killSelection($signal, $text, $selection);
        }

        $cursor = $this->textCursor($text);
        $end = $this->lineEnd($text, $cursor);

        if ($end === $cursor && $cursor < mb_strlen($text)) {
            $end++;
        }

        return $this->killRange($signal, $text, $cursor, $end, $cursor);
    }

    private function killPreviousWord(Signal $signal): bool
    {
        $text = (string) $signal->get();
        $selection = $this->selectionRange($text);

        if ($selection !== null) {
            return $this->killSelection($signal, $text, $selection);
        }

        $cursor = $this->textCursor($text);
        $start = $this->previousWord($text, $cursor);

        return $this->killRange($signal, $text, $start, $cursor, $start);
    }

    private function killNextWord(Signal $signal): bool
    {
        $text = (string) $signal->get();
        $selection = $this->selectionRange($text);

        if ($selection !== null) {
            return $this->killSelection($signal, $text, $selection);
        }

        $cursor = $this->textCursor($text);
        $end = $this->nextWord($text, $cursor);

        return $this->killRange($signal, $text, $cursor, $end, $cursor);
    }

    private function killRange(Signal $signal, string $text, int $start, int $end, int $cursor): bool
    {
        if ($start === $end) {
            return true;
        }

        $this->setKillRing(mb_substr($text, $start, $end - $start));
        $signal->set($this->splice($text, $start, $end));
        $this->setCursor($cursor);
        $this->clearSelection();

        return true;
    }

    /** @param array{int, int} $selection */
    private function killSelection(Signal $signal, string $text, array $selection): bool
    {
        [$start, $end] = $selection;

        return $this->killRange($signal, $text, $start, $end, $start);
    }

    private function yank(Signal $signal): bool
    {
        $ring = $this->inputKillRingSignal();

        if ((string) $ring->get() === '') {
            return false;
        }

        $this->insertText($signal, (string) $ring->get());

        return true;
    }

    private function textCursor(string $text): int
    {
        return $this->clampCursor((int) $this->inputCursorSignal()->get(), $text);
    }

    private function setCursor(int $cursor, ?string $text = null): void
    {
        $signal = $this->inputCursorSignal();

        $text ??= (string) $this->inputSignal()->get();
        $signal->set($this->clampCursor($cursor, $text));
    }

    private function setKillRing(string $text): void
    {
        $this->inputKillRingSignal()->set($text);
    }

    /** @return ?array{int, int} */
    private function selectionRange(string $text): ?array
    {
        $anchor = $this->inputSelectionAnchorSignal()->get();

        if (!is_int($anchor)) {
            return null;
        }

        $cursor = $this->textCursor($text);
        $anchor = $this->clampCursor($anchor, $text);

        if ($anchor === $cursor) {
            return null;
        }

        return [min($anchor, $cursor), max($anchor, $cursor)];
    }

    private function updateSelection(string $text, int $anchor, bool $selecting): void
    {
        if (!$selecting) {
            $this->clearSelection();

            return;
        }

        $selection = $this->inputSelectionAnchorSignal();

        if (!is_int($selection->get())) {
            $selection->set($this->clampCursor($anchor, $text));
        }

        if ($this->selectionRange($text) === null) {
            $this->clearSelection();
        }
    }

    private function clearSelection(): void
    {
        $this->inputSelectionAnchorSignal()->set(null);
    }
}
