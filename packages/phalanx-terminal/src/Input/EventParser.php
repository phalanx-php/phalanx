<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Input;

final class EventParser
{
    private string $buffer = '';
    private bool $inPaste = false;
    private string $pasteBuffer = '';

    private const string PASTE_START = "\033[200~";
    private const string PASTE_END = "\033[201~";

    /** @return list<InputEvent> */
    public function parse(string $data): array
    {
        $this->buffer .= $data;
        $events = [];

        while ($this->buffer !== '') {
            if ($this->inPaste) {
                $endPos = strpos($this->buffer, self::PASTE_END);

                if ($endPos === false) {
                    $this->pasteBuffer .= $this->buffer;
                    $this->buffer = '';
                    break;
                }

                $this->pasteBuffer .= substr($this->buffer, 0, $endPos);
                $this->buffer = substr($this->buffer, $endPos + strlen(self::PASTE_END));
                $events[] = new PasteEvent($this->pasteBuffer);
                $this->pasteBuffer = '';
                $this->inPaste = false;
                continue;
            }

            if (str_starts_with($this->buffer, self::PASTE_START)) {
                $this->buffer = substr($this->buffer, strlen(self::PASTE_START));
                $this->inPaste = true;
                continue;
            }

            $event = $this->parseOne();

            if ($event === null) {
                break;
            }

            $events[] = $event;
        }

        return $events;
    }

    private function parseOne(): ?InputEvent
    {
        $b = $this->buffer;

        if ($b === '') {
            return null;
        }

        $first = ord($b[0]);

        if ($first === 0x1B) {
            return $this->parseEscape();
        }

        if ($first === 0x0D || $first === 0x0A) {
            $this->buffer = substr($b, 1);
            return new KeyEvent(Key::Enter);
        }

        if ($first === 0x09) {
            $this->buffer = substr($b, 1);
            return new KeyEvent(Key::Tab);
        }

        if ($first === 0x7F || $first === 0x08) {
            $this->buffer = substr($b, 1);
            return new KeyEvent(Key::Backspace);
        }

        if ($first >= 1 && $first <= 26) {
            $this->buffer = substr($b, 1);
            $char = chr($first + 96);
            return new KeyEvent($char, ctrl: true);
        }

        $char = mb_substr($b, 0, 1);
        $byteLen = strlen($char);
        $this->buffer = substr($b, $byteLen);

        if ($char === ' ') {
            return new KeyEvent(Key::Space);
        }

        return new KeyEvent($char);
    }

    private function parseEscape(): ?InputEvent
    {
        $b = $this->buffer;

        if (strlen($b) === 1) {
            return null;
        }

        if ($b[1] === '[') {
            return $this->parseCsi();
        }

        if ($b[1] === 'O') {
            return $this->parseSs3();
        }

        $this->buffer = substr($b, 2);
        $char = $b[1];

        return new KeyEvent($char, alt: true);
    }

    private function parseCsi(): ?InputEvent
    {
        $b = $this->buffer;

        if (strlen($b) < 3) {
            return null;
        }

        if ($b[2] === '<') {
            return $this->parseSgrMouse();
        }

        $paramEnd = 2;

        while ($paramEnd < strlen($b) && (($b[$paramEnd] >= '0' && $b[$paramEnd] <= '9') || $b[$paramEnd] === ';')) {
            $paramEnd++;
        }

        if ($paramEnd >= strlen($b)) {
            return null;
        }

        $finalByte = $b[$paramEnd];
        $params = substr($b, 2, $paramEnd - 2);
        $this->buffer = substr($b, $paramEnd + 1);

        return match ($finalByte) {
            'A', 'B', 'C', 'D' => $this->parseArrow($finalByte, $params),
            'H' => new KeyEvent(Key::Home),
            'F' => new KeyEvent(Key::End),
            '~' => $this->parseTilde($params),
            default => new KeyEvent($finalByte),
        };
    }

    private function parseArrow(string $direction, string $params): KeyEvent
    {
        $key = match ($direction) {
            'A' => Key::Up,
            'B' => Key::Down,
            'C' => Key::Right,
            'D' => Key::Left,
        };

        $modifier = 1;
        if (str_contains($params, ';')) {
            $parts = explode(';', $params);
            $modifier = (int) ($parts[1] ?? 1);
        }

        $shift = (bool) (($modifier - 1) & 1);
        $alt   = (bool) (($modifier - 1) & 2);
        $ctrl  = (bool) (($modifier - 1) & 4);
        $super = (bool) (($modifier - 1) & 8);

        return new KeyEvent($key, ctrl: $ctrl || $super, alt: $alt, shift: $shift);
    }

    private function parseTilde(string $params): KeyEvent
    {
        $parts = explode(';', $params);
        $code = (int) ($parts[0] ?? 0);
        $modifier = (int) ($parts[1] ?? 1);

        $ctrl = ($modifier - 1) & 4;
        $alt = ($modifier - 1) & 2;
        $shift = ($modifier - 1) & 1;

        $key = match ($code) {
            1 => Key::Home,
            2 => Key::Insert,
            3 => Key::Delete,
            4 => Key::End,
            5 => Key::PageUp,
            6 => Key::PageDown,
            15 => Key::F5,
            17 => Key::F6,
            18 => Key::F7,
            19 => Key::F8,
            20 => Key::F9,
            21 => Key::F10,
            23 => Key::F11,
            24 => Key::F12,
            default => Key::Escape,
        };

        return new KeyEvent($key, ctrl: (bool) $ctrl, alt: (bool) $alt, shift: (bool) $shift);
    }

    private function parseSs3(): ?InputEvent
    {
        $b = $this->buffer;

        if (strlen($b) < 3) {
            return null;
        }

        $char = $b[2];
        $this->buffer = substr($b, 3);

        return match ($char) {
            'A' => new KeyEvent(Key::Up),
            'B' => new KeyEvent(Key::Down),
            'C' => new KeyEvent(Key::Right),
            'D' => new KeyEvent(Key::Left),
            'H' => new KeyEvent(Key::Home),
            'F' => new KeyEvent(Key::End),
            'P' => new KeyEvent(Key::F1),
            'Q' => new KeyEvent(Key::F2),
            'R' => new KeyEvent(Key::F3),
            'S' => new KeyEvent(Key::F4),
            default => new KeyEvent($char, alt: true),
        };
    }

    private function parseSgrMouse(): ?InputEvent
    {
        $b = $this->buffer;

        $end = strpos($b, 'M', 3) ?: strpos($b, 'm', 3);

        if ($end === false) {
            return null;
        }

        $params = substr($b, 3, $end - 3);
        $release = $b[$end] === 'm';
        $this->buffer = substr($b, $end + 1);

        $parts = explode(';', $params);

        if (count($parts) < 3) {
            return new KeyEvent(Key::Escape);
        }

        $code = (int) $parts[0];
        $x = (int) $parts[1] - 1;
        $y = (int) $parts[2] - 1;

        $ctrl = (bool) ($code & 16);
        $alt = (bool) ($code & 8);
        $shift = (bool) ($code & 4);

        $buttonCode = $code & 3;
        $motion = (bool) ($code & 32);
        $scroll = (bool) ($code & 64);

        if ($scroll) {
            $button = $buttonCode === 0 ? MouseButton::ScrollUp : MouseButton::ScrollDown;
            return new MouseEvent($button, MouseAction::Press, $x, $y, $ctrl, $alt, $shift);
        }

        if ($motion && $buttonCode === 3) {
            return new MouseEvent(MouseButton::None, MouseAction::Move, $x, $y, $ctrl, $alt, $shift);
        }

        $button = match ($buttonCode) {
            0 => MouseButton::Left,
            1 => MouseButton::Middle,
            2 => MouseButton::Right,
            default => MouseButton::None,
        };

        $action = $release ? MouseAction::Release : ($motion ? MouseAction::Move : MouseAction::Press);

        return new MouseEvent($button, $action, $x, $y, $ctrl, $alt, $shift);
    }
}
