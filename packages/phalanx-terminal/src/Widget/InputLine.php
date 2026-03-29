<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Widget;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Input\Key;
use Phalanx\Terminal\Input\KeyEvent;
use Phalanx\Terminal\Style\Style;

final class InputLine implements Widget
{
    private string $value = '';
    private int $cursor = 0;
    private int $scrollOffset = 0;

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

    public function handleKey(KeyEvent $event): ?string
    {
        if ($event->is(Key::Enter)) {
            return $this->clear();
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

        if ($event->is(Key::Left)) {
            $this->cursor = max(0, $this->cursor - 1);

            return null;
        }

        if ($event->is(Key::Right)) {
            $this->cursor = min(mb_strlen($this->value), $this->cursor + 1);

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
}
