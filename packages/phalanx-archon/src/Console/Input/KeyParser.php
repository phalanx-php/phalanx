<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Input;

/**
 * Translates raw stdin bytes into canonical key name strings that prompts switch on.
 *
 * Self-contained parser. Reads stream-fragmented byte chunks, accumulates partial
 * escape sequences across calls, and emits one canonical token per recognised
 * key event. Bracketed paste payload is split into individual codepoints so
 * text inputs insert naturally without special-casing paste in each prompt.
 *
 * Canonical names:
 *   named keys       - 'enter', 'tab', 'backspace', 'space', 'escape', 'delete',
 *                      'up', 'down', 'left', 'right', 'home', 'end',
 *                      'pageup', 'pagedown', 'insert', 'f1'..'f12'
 *   ctrl combos      - 'ctrl-a' .. 'ctrl-z'
 *   alt word motion  - 'alt-left' / 'alt-right' (Terminal.app ESC+b/f and iTerm2 CSI 1;3D/C)
 *   printable chars  - pass through as the codepoint string
 *
 * parseOne() and friends return `false` to signal "incomplete sequence, stop and
 * wait for more bytes"; `null` means "consumed bytes, no key produced, keep going";
 * a string is the canonical key.
 */
final class KeyParser
{
    private const string PASTE_START = "\033[200~";
    private const string PASTE_END   = "\033[201~";

    private string $buffer      = '';
    private bool $inPaste       = false;
    private string $pasteBuffer = '';

    /** @return list<string> */
    public function parse(string $data): array
    {
        $keys          = [];
        $this->buffer .= $data;

        while ($this->buffer !== '') {
            if ($this->inPaste) {
                if (!$this->drainPaste($keys)) {
                    break;
                }
                continue;
            }

            if (str_starts_with($this->buffer, self::PASTE_START)) {
                $this->buffer  = substr($this->buffer, strlen(self::PASTE_START));
                $this->inPaste = true;
                continue;
            }

            $key = $this->parseOne();

            if ($key === false) {
                break;
            }

            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function drainPaste(array &$keys): bool
    {
        $endPos = strpos($this->buffer, self::PASTE_END);

        if ($endPos === false) {
            $this->pasteBuffer .= $this->buffer;
            $this->buffer       = '';
            return false;
        }

        $this->pasteBuffer .= substr($this->buffer, 0, $endPos);
        $this->buffer       = substr($this->buffer, $endPos + strlen(self::PASTE_END));

        foreach (mb_str_split($this->pasteBuffer) as $char) {
            if ($char === "\r" || $char === "\n") {
                $keys[] = 'enter';
            } elseif ($char === "\t") {
                $keys[] = 'tab';
            } elseif (mb_ord($char) >= 32) {
                $keys[] = $char;
            }
        }

        $this->pasteBuffer = '';
        $this->inPaste     = false;

        return true;
    }

    private function parseOne(): string|false|null
    {
        $b     = $this->buffer;
        $first = ord($b[0]);

        if ($first === 0x1B) {
            return $this->parseEscape();
        }

        if ($first === 0x0D || $first === 0x0A) {
            $this->buffer = substr($b, 1);
            return 'enter';
        }

        if ($first === 0x09) {
            $this->buffer = substr($b, 1);
            return 'tab';
        }

        if ($first === 0x7F || $first === 0x08) {
            $this->buffer = substr($b, 1);
            return 'backspace';
        }

        if ($first >= 1 && $first <= 26) {
            $this->buffer = substr($b, 1);
            return 'ctrl-' . chr($first + 96);
        }

        $char         = mb_substr($b, 0, 1);
        $byteLen      = strlen($char);
        $this->buffer = substr($b, $byteLen);

        if ($char === ' ') {
            return 'space';
        }

        if (mb_ord($char) < 32) {
            return null;
        }

        return $char;
    }

    private function parseEscape(): string|false|null
    {
        $b = $this->buffer;

        if (strlen($b) === 1) {
            return false;
        }

        if ($b[1] === '[') {
            return $this->parseCsi();
        }

        if ($b[1] === 'O') {
            return $this->parseSs3();
        }

        $this->buffer = substr($b, 2);
        $next         = $b[1];

        // Terminal.app ESC+b/f → alt-left/alt-right word motion.
        if ($next === 'b') {
            return 'alt-left';
        }
        if ($next === 'f') {
            return 'alt-right';
        }

        return null;
    }

    private function parseCsi(): string|false|null
    {
        $b = $this->buffer;

        if (strlen($b) < 3) {
            return false;
        }

        // Drop SGR-mouse payloads to match prior behaviour.
        if ($b[2] === '<') {
            $end = strpos($b, 'M', 3);
            if ($end === false) {
                $end = strpos($b, 'm', 3);
            }
            if ($end === false) {
                return false;
            }
            $this->buffer = substr($b, $end + 1);
            return null;
        }

        $paramEnd = 2;
        $len      = strlen($b);

        while ($paramEnd < $len) {
            $c = $b[$paramEnd];
            if (($c >= '0' && $c <= '9') || $c === ';') {
                $paramEnd++;
                continue;
            }
            break;
        }

        if ($paramEnd >= $len) {
            return false;
        }

        $finalByte    = $b[$paramEnd];
        $params       = substr($b, 2, $paramEnd - 2);
        $this->buffer = substr($b, $paramEnd + 1);

        return match ($finalByte) {
            'A', 'B', 'C', 'D' => $this->arrowKey($finalByte, $params),
            'H'                => 'home',
            'F'                => 'end',
            '~'                => $this->tildeKey($params),
            default            => null,
        };
    }

    private function arrowKey(string $direction, string $params): string
    {
        $modifier = 1;
        if (str_contains($params, ';')) {
            $parts    = explode(';', $params);
            $modifier = (int) ($parts[1] ?? 1);
        }

        $alt = (($modifier - 1) & 2) !== 0;

        if ($alt && ($direction === 'D' || $direction === 'C')) {
            return $direction === 'D' ? 'alt-left' : 'alt-right';
        }

        return match ($direction) {
            'A'     => 'up',
            'B'     => 'down',
            'C'     => 'right',
            'D'     => 'left',
            default => '',
        };
    }

    private function tildeKey(string $params): ?string
    {
        $parts = explode(';', $params);
        $code  = (int) ($parts[0] ?? 0);

        return match ($code) {
            1       => 'home',
            2       => 'insert',
            3       => 'delete',
            4       => 'end',
            5       => 'pageup',
            6       => 'pagedown',
            15      => 'f5',
            17      => 'f6',
            18      => 'f7',
            19      => 'f8',
            20      => 'f9',
            21      => 'f10',
            23      => 'f11',
            24      => 'f12',
            default => null,
        };
    }

    private function parseSs3(): string|false|null
    {
        $b = $this->buffer;

        if (strlen($b) < 3) {
            return false;
        }

        $char         = $b[2];
        $this->buffer = substr($b, 3);

        return match ($char) {
            'A'     => 'up',
            'B'     => 'down',
            'C'     => 'right',
            'D'     => 'left',
            'H'     => 'home',
            'F'     => 'end',
            'P'     => 'f1',
            'Q'     => 'f2',
            'R'     => 'f3',
            'S'     => 'f4',
            default => null,
        };
    }
}
