<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Widget;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Style\Style;

final class InputLine implements Widget
{
    private string $value = '';
    private int $cursor = 0;
    private int $scrollOffset = 0;

    /** @var list<string> */
    private array $history = [];
    private int $historyIndex = -1;
    private string $historyStash = '';

    private Style $style;
    private Style $cursorStyle;

    public function __construct(
        private string $prompt = '> ',
        ?Style $style = null,
        ?Style $cursorStyle = null,
    ) {
        $this->style = $style ?? Style::new();
        $this->cursorStyle = $cursorStyle ?? Style::new()->reverse();
    }

    public string $text {
        get => $this->value;
    }

    public int $cursorPosition {
        get => $this->cursor;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
        $this->cursor = mb_strlen($value);
    }

    public function clear(): string
    {
        $prev = $this->value;
        $this->value = '';
        $this->cursor = 0;
        $this->scrollOffset = 0;

        return $prev;
    }

    public function insertText(string $text): void
    {
        $clean = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        $this->value = mb_substr($this->value, 0, $this->cursor)
            . $clean
            . mb_substr($this->value, $this->cursor);
        $this->cursor += mb_strlen($clean);
    }

    public function handleKey(KeyEvent $event): ?string
    {
        if ($event->is(Key::Enter)) {
            $text = $this->clear();
            if ($text !== '') {
                $this->history[] = $text;
            }
            $this->historyIndex = -1;
            $this->historyStash = '';

            return $text;
        }

        if ($event->is(Key::Backspace)) {
            if ($this->cursor > 0) {
                $this->value = mb_substr($this->value, 0, $this->cursor - 1)
                    . mb_substr($this->value, $this->cursor);
                $this->cursor--;
            }

            return null;
        }

        if ($event->is(Key::Delete)) {
            $len = mb_strlen($this->value);

            if ($this->cursor < $len) {
                $this->value = mb_substr($this->value, 0, $this->cursor)
                    . mb_substr($this->value, $this->cursor + 1);
            }

            return null;
        }

        // Ctrl+Left or Cmd+Left → start of line
        if ($event->is(Key::Left) && $event->ctrl) {
            $this->cursor = 0;

            return null;
        }

        // Alt+Left (Opt+Left) → word boundary left (CSI format)
        if ($event->is(Key::Left) && $event->alt) {
            $this->cursor = $this->wordBoundaryLeft();

            return null;
        }

        // Meta-b (Opt+Left on macOS terminals) → word boundary left
        if ($event->alt && $event->is('b')) {
            $this->cursor = $this->wordBoundaryLeft();

            return null;
        }

        if ($event->is(Key::Left)) {
            $this->cursor = max(0, $this->cursor - 1);

            return null;
        }

        // Ctrl+Right or Cmd+Right → end of line
        if ($event->is(Key::Right) && $event->ctrl) {
            $this->cursor = mb_strlen($this->value);

            return null;
        }

        // Alt+Right (Opt+Right) → word boundary right (CSI format)
        if ($event->is(Key::Right) && $event->alt) {
            $this->cursor = $this->wordBoundaryRight();

            return null;
        }

        // Meta-f (Opt+Right on macOS terminals) → word boundary right
        if ($event->alt && $event->is('f')) {
            $this->cursor = $this->wordBoundaryRight();

            return null;
        }

        if ($event->is(Key::Right)) {
            $this->cursor = min(mb_strlen($this->value), $this->cursor + 1);

            return null;
        }

        // Up → history previous
        if ($event->is(Key::Up)) {
            $this->historyUp();

            return null;
        }

        // Down → history next
        if ($event->is(Key::Down)) {
            $this->historyDown();

            return null;
        }

        if ($event->is(Key::Home)) {
            $this->cursor = 0;

            return null;
        }

        if ($event->is(Key::End)) {
            $this->cursor = mb_strlen($this->value);

            return null;
        }

        // Ctrl+A → start of line
        if ($event->ctrl && $event->is('a')) {
            $this->cursor = 0;

            return null;
        }

        // Ctrl+E → end of line
        if ($event->ctrl && $event->is('e')) {
            $this->cursor = mb_strlen($this->value);

            return null;
        }

        // Ctrl+U → kill to start
        if ($event->ctrl && $event->is('u')) {
            $this->value = mb_substr($this->value, $this->cursor);
            $this->cursor = 0;

            return null;
        }

        // Ctrl+K → kill to end
        if ($event->ctrl && $event->is('k')) {
            $this->value = mb_substr($this->value, 0, $this->cursor);

            return null;
        }

        // Ctrl+W → kill word backward
        if ($event->ctrl && $event->is('w')) {
            $boundary = $this->wordBoundaryLeft();
            $this->value = mb_substr($this->value, 0, $boundary)
                . mb_substr($this->value, $this->cursor);
            $this->cursor = $boundary;

            return null;
        }

        if ($event->is(Key::Space)) {
            $this->value = mb_substr($this->value, 0, $this->cursor)
                . ' '
                . mb_substr($this->value, $this->cursor);
            $this->cursor++;

            return null;
        }

        if ($event->isChar()) {
            $char = $event->char();

            if ($char !== null) {
                $this->value = mb_substr($this->value, 0, $this->cursor)
                    . $char
                    . mb_substr($this->value, $this->cursor);
                $this->cursor++;
            }

            return null;
        }

        return null;
    }

    public function render(Rect $area, Buffer $buffer): void
    {
        if ($area->height === 0 || $area->width === 0) {
            return;
        }

        $buffer->fill($area, $this->style);

        $promptLen = mb_strlen($this->prompt);
        $buffer->putString($area->x, $area->y, $this->prompt, $this->style);

        $editableWidth = $area->width - $promptLen;

        if ($editableWidth <= 0) {
            return;
        }

        if ($this->cursor - $this->scrollOffset >= $editableWidth) {
            $this->scrollOffset = $this->cursor - $editableWidth + 1;
        }

        if ($this->cursor < $this->scrollOffset) {
            $this->scrollOffset = $this->cursor;
        }

        $visible = mb_substr($this->value, $this->scrollOffset, $editableWidth);
        $editX = $area->x + $promptLen;

        $buffer->putString($editX, $area->y, $visible, $this->style);

        $cursorX = $editX + ($this->cursor - $this->scrollOffset);

        if ($cursorX < $area->right) {
            $charAtCursor = $this->cursor < mb_strlen($this->value)
                ? mb_substr($this->value, $this->cursor, 1)
                : ' ';

            $buffer->set($cursorX, $area->y, $charAtCursor, $this->cursorStyle);
        }
    }

    private function wordBoundaryLeft(): int
    {
        $pos = $this->cursor;

        while ($pos > 0 && mb_substr($this->value, $pos - 1, 1) === ' ') {
            $pos--;
        }

        while ($pos > 0 && mb_substr($this->value, $pos - 1, 1) !== ' ') {
            $pos--;
        }

        return $pos;
    }

    private function wordBoundaryRight(): int
    {
        $len = mb_strlen($this->value);
        $pos = $this->cursor;

        while ($pos < $len && mb_substr($this->value, $pos, 1) !== ' ') {
            $pos++;
        }

        while ($pos < $len && mb_substr($this->value, $pos, 1) === ' ') {
            $pos++;
        }

        return $pos;
    }

    private function historyUp(): void
    {
        if ($this->history === []) {
            return;
        }

        if ($this->historyIndex === -1) {
            $this->historyStash = $this->value;
            $this->historyIndex = count($this->history) - 1;
        } elseif ($this->historyIndex > 0) {
            $this->historyIndex--;
        } else {
            return;
        }

        $this->value = $this->history[$this->historyIndex];
        $this->cursor = mb_strlen($this->value);
    }

    private function historyDown(): void
    {
        if ($this->historyIndex === -1) {
            return;
        }

        $this->historyIndex++;

        if ($this->historyIndex >= count($this->history)) {
            $this->historyIndex = -1;
            $this->value = $this->historyStash;
            $this->historyStash = '';
        } else {
            $this->value = $this->history[$this->historyIndex];
        }

        $this->cursor = mb_strlen($this->value);
    }
}
